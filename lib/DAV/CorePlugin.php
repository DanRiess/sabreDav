<?php

declare(strict_types=1);

namespace Sabre\DAV;

use Sabre\DAV\Exception\BadRequest;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\Xml\ParseException;

/**
 * The core plugin provides all the basic features for a WebDAV server.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class CorePlugin extends ServerPlugin
{
    /**
     * Reference to server object.
     *
     * @var Server
     */
    protected $server;

    /**
     * Sets up the plugin.
     */
    public function initialize(Server $server)
    {
        $this->server = $server;
        $server->on('method:GET', [$this, 'httpGet']);
        $server->on('method:OPTIONS', [$this, 'httpOptions']);
        $server->on('method:HEAD', [$this, 'httpHead']);
        $server->on('method:DELETE', [$this, 'httpDelete']);
        $server->on('method:PROPFIND', [$this, 'httpPropFind']);
        $server->on('method:PROPPATCH', [$this, 'httpPropPatch']);
        $server->on('method:PUT', [$this, 'httpPut']);
        $server->on('method:MKCOL', [$this, 'httpMkcol']);
        $server->on('method:MOVE', [$this, 'httpMove']);
        $server->on('method:COPY', [$this, 'httpCopy']);
        $server->on('method:REPORT', [$this, 'httpReport']);

        $server->on('propPatch', [$this, 'propPatchProtectedPropertyCheck'], 90);
        $server->on('propPatch', [$this, 'propPatchNodeUpdate'], 200);
        $server->on('propFind', [$this, 'propFind']);
        $server->on('propFind', [$this, 'propFindNode'], 120);
        $server->on('propFind', [$this, 'propFindLate'], 200);

        $server->on('exception', [$this, 'exception']);
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    public function getPluginName()
    {
        return 'core';
    }

    /**
     * This is the default implementation for the GET method.
     *
     * @return bool
     */
    public function httpGet(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof IFile) {
            return;
        }

        if ('HEAD' === $request->getHeader('X-Sabre-Original-Method')) {
            $body = '';
        } else {
            $body = $node->get();

            // Converting string into stream, if needed.
            if (is_string($body)) {
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $body);
                rewind($stream);
                $body = $stream;
            }
        }

        /*
         * TODO: getetag, getlastmodified, getsize should also be used using
         * this method
         */
        $httpHeaders = $this->server->getHTTPHeaders($path);

        /* ContentType needs to get a default, because many webservers will otherwise
         * default to text/html, and we don't want this for security reasons.
         */
        if (!isset($httpHeaders['Content-Type'])) {
            $httpHeaders['Content-Type'] = 'application/octet-stream';
        }

        if (isset($httpHeaders['Content-Length'])) {
            $nodeSize = $httpHeaders['Content-Length'];

            // Need to unset Content-Length, because we'll handle that during figuring out the range
            unset($httpHeaders['Content-Length']);
        } else {
            $nodeSize = null;
        }

        $response->addHeaders($httpHeaders);

        // this function now only checks, if the range string is correctly formatted
        // and returns it (or null if it isn't or it doesn't exist)
        // processing of the ranges will then be done further below
        $rangeHeaderValue = $this->server->getHTTPRangeHeaderValue();
        $ifRange = $request->getHeader('If-Range');
        $ignoreRangeHeader = false;

        // If ifRange is set, and range is specified, we first need to check
        // the precondition.
        if ($nodeSize && $rangeHeaderValue && $ifRange) {
            // if IfRange is parsable as a date we'll treat it as a DateTime
            // otherwise, we must treat it as an etag.
            try {
                $ifRangeDate = new \DateTime($ifRange);

                // It's a date. We must check if the entity is modified since
                // the specified date.
                if (!isset($httpHeaders['Last-Modified'])) {
                    $ignoreRangeHeader = true;
                } else {
                    $modified = new \DateTime($httpHeaders['Last-Modified']);
                    if ($modified > $ifRangeDate) {
                        $ignoreRangeHeader = true;
                    }
                }
            } catch (\Exception $e) {
                // It's an entity. We can do a simple comparison.
                if (!isset($httpHeaders['ETag'])) {
                    $ignoreRangeHeader = true;
                } elseif ($httpHeaders['ETag'] !== $ifRange) {
                    $ignoreRangeHeader = true;
                }
            }
        }

        $ranges = null;

        if (!$ignoreRangeHeader && $nodeSize && $rangeHeaderValue) {
            // preprocess the ranges now
            // this involves removing invalid ranges and security checks
            $ranges = $this->preprocessRanges($rangeHeaderValue, $nodeSize);
        }

        if (!$ignoreRangeHeader && $nodeSize && $ranges) {
            // create a new stream for the partial responses
            $rangeBody = fopen('php://temp', 'r+');

            // create a boundary string that is being used as identifier for multipart ranges
            $boundary = hash('md5', uniqid('', true));

            // create a content length counter
            $contentLength = 0;

            // define the node's content type
            $nodeContentType = $node->getContentType();

            if (!$nodeContentType) {
                $nodeContentType = 'application/octet-stream';
            }

            foreach ($ranges as $range) {
                $start = $range[0];
                $end = $range[1];

                // Streams may advertise themselves as seekable, but still not
                // actually allow fseek.  We'll manually go forward in the stream
                // if fseek failed.
                if (!stream_get_meta_data($body)['seekable'] || -1 === fseek($body, $start, SEEK_SET)) {
                    // don't allow multipart ranges for non-seekable streams
                    // these streams are not rewindable, so they would have to be closed and opened for each range
                    if (count($ranges) > 1) {
                        throw new Exception\RequestedRangeNotSatisfiable('Multipart range requests are not allowed on a non-seekable resource.');
                    }
                    $consumeBlock = 8192;
                    for ($consumed = 0; $start - $consumed > 0;) {
                        if (feof($body)) {
                            throw new Exception\RequestedRangeNotSatisfiable('The start offset ('.$start.') exceeded the size of the entity ('.$consumed.')');
                        }
                        $consumed += strlen(fread($body, min($start - $consumed, $consumeBlock)));
                    }
                }

                // if there is only one range, write it into the response body
                // otherwise, write multipart header info first before writing the data
                if (1 === count($ranges)) {
                    $rangeLength = $end - $start + 1;
                    $consumeBlock = 8192;

                    // write body data into response body (max 8192 bytes per read cycle)
                    while ($rangeLength > 0) {
                        fwrite($rangeBody, fread($body, min($rangeLength, $consumeBlock)));
                        $rangeLength -= min($rangeLength, $consumeBlock);
                    }

                    // update content length
                    $contentLength = $end - $start + 1;
                } else {
                    // define new boundary section and write to response body
                    $boundarySection = '--'.$boundary."\nContent-Type: ".$nodeContentType."\nContent-Range: bytes ".$start.'-'.$end.'/'.$nodeSize."\n\n";
                    fwrite($rangeBody, $boundarySection);

                    // write body data into response body (max 8192 bytes per read cycle)
                    $rangeLength = $end - $start + 1;
                    $consumeBlock = 8192;
                    while ($rangeLength > 0) {
                        fwrite($rangeBody, fread($body, min($rangeLength, $consumeBlock)));
                        $rangeLength -= min($rangeLength, $consumeBlock);
                    }

                    // write line breaks at the end of section
                    fwrite($rangeBody, "\n\n");

                    // update content length
                    // +2 for double linebreak at the end of each section
                    $contentLength += strlen($boundarySection) + ($end - $start + 1) + 2;
                }
            }

            // rewind the range body after the loop
            rewind($rangeBody);

            if (1 === count($ranges)) {
                // if only one range, content range header MUST be set (according to spec)
                $response->setHeader('Content-Range', 'bytes '.$start.'-'.$end.'/'.$nodeSize);
            } else {
                // if multiple ranges, content range header MUST NOT be set (according to spec)
                // content type header MUST be of type multipart/byteranges
                // more specific types can be set in each body section header
                $response->setHeader('Content-Type', 'multipart/byteranges; boundary='.$boundary);
            }

            $response->setHeader('Content-Length', $contentLength);

            $response->setStatus(206);
            $response->setBody($rangeBody);
        } else {
            if ($nodeSize) {
                $response->setHeader('Content-Length', $nodeSize);
            }
            $response->setStatus(200);
            $response->setBody($body);
        }

        return false;
    }

    /**
     * HTTP OPTIONS.
     *
     * @return bool
     */
    public function httpOptions(RequestInterface $request, ResponseInterface $response)
    {
        $methods = $this->server->getAllowedMethods($request->getPath());

        $response->setHeader('Allow', strtoupper(implode(', ', $methods)));
        $features = ['1', '3', 'extended-mkcol'];

        foreach ($this->server->getPlugins() as $plugin) {
            $features = array_merge($features, $plugin->getFeatures());
        }

        $response->setHeader('DAV', implode(', ', $features));
        $response->setHeader('MS-Author-Via', 'DAV');
        $response->setHeader('Accept-Ranges', 'bytes');
        $response->setHeader('Content-Length', '0');
        $response->setStatus(200);

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * HTTP HEAD.
     *
     * This method is normally used to take a peak at a url, and only get the
     * HTTP response headers, without the body. This is used by clients to
     * determine if a remote file was changed, so they can use a local cached
     * version, instead of downloading it again
     *
     * @return bool
     */
    public function httpHead(RequestInterface $request, ResponseInterface $response)
    {
        // This is implemented by changing the HEAD request to a GET request,
        // and telling the request handler that is doesn't need to create the body.
        $subRequest = clone $request;
        $subRequest->setMethod('GET');
        $subRequest->setHeader('X-Sabre-Original-Method', 'HEAD');

        try {
            $this->server->invokeMethod($subRequest, $response, false);
        } catch (Exception\NotImplemented $e) {
            // Some clients may do HEAD requests on collections, however, GET
            // requests and HEAD requests _may_ not be defined on a collection,
            // which would trigger a 501.
            // This breaks some clients though, so we're transforming these
            // 501s into 200s.
            $response->setStatus(200);
            $response->setBody('');
            $response->setHeader('Content-Type', 'text/plain');
            $response->setHeader('X-Sabre-Real-Status', $e->getHTTPCode());
        }

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * HTTP Delete.
     *
     * The HTTP delete method, deletes a given uri
     */
    public function httpDelete(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        if (!$this->server->emit('beforeUnbind', [$path])) {
            return false;
        }
        $this->server->tree->delete($path);
        $this->server->emit('afterUnbind', [$path]);

        $response->setStatus(204);
        $response->setHeader('Content-Length', '0');

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * WebDAV PROPFIND.
     *
     * This WebDAV method requests information about an uri resource, or a list of resources
     * If a client wants to receive the properties for a single resource it will add an HTTP Depth: header with a 0 value
     * If the value is 1, it means that it also expects a list of sub-resources (e.g.: files in a directory)
     *
     * The request body contains an XML data structure that has a list of properties the client understands
     * The response body is also an xml document, containing information about every uri resource and the requested properties
     *
     * It has to return a HTTP 207 Multi-status status code
     */
    public function httpPropFind(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        $requestBody = $request->getBodyAsString();
        if (strlen($requestBody)) {
            try {
                $propFindXml = $this->server->xml->expect('{DAV:}propfind', $requestBody);
            } catch (ParseException $e) {
                throw new BadRequest($e->getMessage(), 0, $e);
            }
        } else {
            $propFindXml = new Xml\Request\PropFind();
            $propFindXml->allProp = true;
            $propFindXml->properties = [];
        }

        $depth = $this->server->getHTTPDepth(1);
        // The only two options for the depth of a propfind is 0 or 1 - as long as depth infinity is not enabled
        if (!$this->server->enablePropfindDepthInfinity && 0 != $depth) {
            $depth = 1;
        }

        $newProperties = $this->server->getPropertiesIteratorForPath($path, $propFindXml->properties, $depth);

        // This is a multi-status response
        $response->setStatus(207);
        $response->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $response->setHeader('Vary', 'Brief,Prefer');

        // Normally this header is only needed for OPTIONS responses, however..
        // iCal seems to also depend on these being set for PROPFIND. Since
        // this is not harmful, we'll add it.
        $features = ['1', '3', 'extended-mkcol'];
        foreach ($this->server->getPlugins() as $plugin) {
            $features = array_merge($features, $plugin->getFeatures());
        }
        $response->setHeader('DAV', implode(', ', $features));

        $prefer = $this->server->getHTTPPrefer();
        $minimal = 'minimal' === $prefer['return'];

        $data = $this->server->generateMultiStatus($newProperties, $minimal);
        $response->setBody($data);

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * WebDAV PROPPATCH.
     *
     * This method is called to update properties on a Node. The request is an XML body with all the mutations.
     * In this XML body it is specified which properties should be set/updated and/or deleted
     *
     * @return bool
     */
    public function httpPropPatch(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        try {
            $propPatch = $this->server->xml->expect('{DAV:}propertyupdate', $request->getBody());
        } catch (ParseException $e) {
            throw new BadRequest($e->getMessage(), 0, $e);
        }
        $newProperties = $propPatch->properties;

        $result = $this->server->updateProperties($path, $newProperties);

        $prefer = $this->server->getHTTPPrefer();
        $response->setHeader('Vary', 'Brief,Prefer');

        if ('minimal' === $prefer['return']) {
            // If return-minimal is specified, we only have to check if the
            // request was successful, and don't need to return the
            // multi-status.
            $ok = true;
            foreach ($result as $prop => $code) {
                if ((int) $code > 299) {
                    $ok = false;
                }
            }

            if ($ok) {
                $response->setStatus(204);

                return false;
            }
        }

        $response->setStatus(207);
        $response->setHeader('Content-Type', 'application/xml; charset=utf-8');

        // Reorganizing the result for generateMultiStatus
        $multiStatus = [];
        foreach ($result as $propertyName => $code) {
            if (isset($multiStatus[$code])) {
                $multiStatus[$code][$propertyName] = null;
            } else {
                $multiStatus[$code] = [$propertyName => null];
            }
        }
        $multiStatus['href'] = $path;

        $response->setBody(
            $this->server->generateMultiStatus([$multiStatus])
        );

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * HTTP PUT method.
     *
     * This HTTP method updates a file, or creates a new one.
     *
     * If a new resource was created, a 201 Created status code should be returned. If an existing resource is updated, it's a 204 No Content
     *
     * @return bool
     */
    public function httpPut(RequestInterface $request, ResponseInterface $response)
    {
        $body = $request->getBodyAsStream();
        $path = $request->getPath();

        // Intercepting Content-Range
        if ($request->getHeader('Content-Range')) {
            /*
               An origin server that allows PUT on a given target resource MUST send
               a 400 (Bad Request) response to a PUT request that contains a
               Content-Range header field.

               Reference: http://tools.ietf.org/html/rfc7231#section-4.3.4
            */
            throw new Exception\BadRequest('Content-Range on PUT requests are forbidden.');
        }

        // Intercepting the Finder problem
        if (($expected = $request->getHeader('X-Expected-Entity-Length')) && $expected > 0) {
            /*
            Many webservers will not cooperate well with Finder PUT requests,
            because it uses 'Chunked' transfer encoding for the request body.

            The symptom of this problem is that Finder sends files to the
            server, but they arrive as 0-length files in PHP.

            If we don't do anything, the user might think they are uploading
            files successfully, but they end up empty on the server. Instead,
            we throw back an error if we detect this.

            The reason Finder uses Chunked, is because it thinks the files
            might change as it's being uploaded, and therefore the
            Content-Length can vary.

            Instead it sends the X-Expected-Entity-Length header with the size
            of the file at the very start of the request. If this header is set,
            but we don't get a request body we will fail the request to
            protect the end-user.
            */

            // Only reading first byte
            $firstByte = fread($body, 1);
            if (1 !== strlen($firstByte)) {
                throw new Exception\Forbidden('This server is not compatible with OS/X finder. Consider using a different WebDAV client or webserver.');
            }

            // The body needs to stay intact, so we copy everything to a
            // temporary stream.

            $newBody = fopen('php://temp', 'r+');
            fwrite($newBody, $firstByte);
            stream_copy_to_stream($body, $newBody);
            rewind($newBody);

            $body = $newBody;
        }

        if ($this->server->tree->nodeExists($path)) {
            $node = $this->server->tree->getNodeForPath($path);

            // If the node is a collection, we'll deny it
            if (!($node instanceof IFile)) {
                throw new Exception\Conflict('PUT is not allowed on non-files.');
            }
            if (!$this->server->updateFile($path, $body, $etag)) {
                return false;
            }

            $response->setHeader('Content-Length', '0');
            if ($etag) {
                $response->setHeader('ETag', $etag);
            }
            $response->setStatus(204);
        } else {
            $etag = null;
            // If we got here, the resource didn't exist yet.
            if (!$this->server->createFile($path, $body, $etag)) {
                // For one reason or another the file was not created.
                return false;
            }

            $response->setHeader('Content-Length', '0');
            if ($etag) {
                $response->setHeader('ETag', $etag);
            }
            $response->setStatus(201);
        }

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * WebDAV MKCOL.
     *
     * The MKCOL method is used to create a new collection (directory) on the server
     *
     * @return bool
     */
    public function httpMkcol(RequestInterface $request, ResponseInterface $response)
    {
        $requestBody = $request->getBodyAsString();
        $path = $request->getPath();

        if ($requestBody) {
            $contentType = $request->getHeader('Content-Type');
            if (null === $contentType || (0 !== strpos($contentType, 'application/xml') && 0 !== strpos($contentType, 'text/xml'))) {
                // We must throw 415 for unsupported mkcol bodies
                throw new Exception\UnsupportedMediaType('The request body for the MKCOL request must have an xml Content-Type');
            }

            try {
                $mkcol = $this->server->xml->expect('{DAV:}mkcol', $requestBody);
            } catch (\Sabre\Xml\ParseException $e) {
                throw new Exception\BadRequest($e->getMessage(), 0, $e);
            }

            $properties = $mkcol->getProperties();

            if (!isset($properties['{DAV:}resourcetype'])) {
                throw new Exception\BadRequest('The mkcol request must include a {DAV:}resourcetype property');
            }
            $resourceType = $properties['{DAV:}resourcetype']->getValue();
            unset($properties['{DAV:}resourcetype']);
        } else {
            $properties = [];
            $resourceType = ['{DAV:}collection'];
        }

        $mkcol = new MkCol($resourceType, $properties);

        $result = $this->server->createCollection($path, $mkcol);

        if (is_array($result)) {
            $response->setStatus(207);
            $response->setHeader('Content-Type', 'application/xml; charset=utf-8');

            $response->setBody(
                $this->server->generateMultiStatus([$result])
            );
        } else {
            $response->setHeader('Content-Length', '0');
            $response->setStatus(201);
        }

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * WebDAV HTTP MOVE method.
     *
     * This method moves one uri to a different uri. A lot of the actual request processing is done in getCopyMoveInfo
     *
     * @return bool
     */
    public function httpMove(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        $moveInfo = $this->server->getCopyAndMoveInfo($request);

        if ($moveInfo['destinationExists']) {
            if (!$this->server->emit('beforeUnbind', [$moveInfo['destination']])) {
                return false;
            }
        }
        if (!$this->server->emit('beforeUnbind', [$path])) {
            return false;
        }
        if (!$this->server->emit('beforeBind', [$moveInfo['destination']])) {
            return false;
        }
        if (!$this->server->emit('beforeMove', [$path, $moveInfo['destination']])) {
            return false;
        }

        if ($moveInfo['destinationExists']) {
            $this->server->tree->delete($moveInfo['destination']);
            $this->server->emit('afterUnbind', [$moveInfo['destination']]);
        }

        $this->server->tree->move($path, $moveInfo['destination']);

        // Its important afterMove is called before afterUnbind, because it
        // allows systems to transfer data from one path to another.
        // PropertyStorage uses this. If afterUnbind was first, it would clean
        // up all the properties before it has a chance.
        $this->server->emit('afterMove', [$path, $moveInfo['destination']]);
        $this->server->emit('afterUnbind', [$path]);
        $this->server->emit('afterBind', [$moveInfo['destination']]);

        // If a resource was overwritten we should send a 204, otherwise a 201
        $response->setHeader('Content-Length', '0');
        $response->setStatus($moveInfo['destinationExists'] ? 204 : 201);

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * WebDAV HTTP COPY method.
     *
     * This method copies one uri to a different uri, and works much like the MOVE request
     * A lot of the actual request processing is done in getCopyMoveInfo
     *
     * @return bool
     */
    public function httpCopy(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        $copyInfo = $this->server->getCopyAndMoveInfo($request);

        if (!$this->server->emit('beforeBind', [$copyInfo['destination']])) {
            return false;
        }
        if (!$this->server->emit('beforeCopy', [$path, $copyInfo['destination']])) {
            return false;
        }

        if ($copyInfo['destinationExists']) {
            if (!$this->server->emit('beforeUnbind', [$copyInfo['destination']])) {
                return false;
            }
            $this->server->tree->delete($copyInfo['destination']);
        }

        $this->server->tree->copy($path, $copyInfo['destination']);
        $this->server->emit('afterCopy', [$path, $copyInfo['destination']]);
        $this->server->emit('afterBind', [$copyInfo['destination']]);

        // If a resource was overwritten we should send a 204, otherwise a 201
        $response->setHeader('Content-Length', '0');
        $response->setStatus($copyInfo['destinationExists'] ? 204 : 201);

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * HTTP REPORT method implementation.
     *
     * Although the REPORT method is not part of the standard WebDAV spec (it's from rfc3253)
     * It's used in a lot of extensions, so it made sense to implement it into the core.
     *
     * @return bool
     */
    public function httpReport(RequestInterface $request, ResponseInterface $response)
    {
        $path = $request->getPath();

        $result = $this->server->xml->parse(
            $request->getBody(),
            $request->getUrl(),
            $rootElementName
        );

        if ($this->server->emit('report', [$rootElementName, $result, $path])) {
            // If emit returned true, it means the report was not supported
            throw new Exception\ReportNotSupported();
        }

        // Sending back false will interrupt the event chain and tell the server
        // we've handled this method.
        return false;
    }

    /**
     * This method is called during property updates.
     *
     * Here we check if a user attempted to update a protected property and
     * ensure that the process fails if this is the case.
     *
     * @param string $path
     */
    public function propPatchProtectedPropertyCheck($path, PropPatch $propPatch)
    {
        // Comparing the mutation list to the list of protected properties.
        $mutations = $propPatch->getMutations();

        $protected = array_intersect(
            $this->server->protectedProperties,
            array_keys($mutations)
        );

        if ($protected) {
            $propPatch->setResultCode($protected, 403);
        }
    }

    /**
     * This method is called during property updates.
     *
     * Here we check if a node implements IProperties and let the node handle
     * updating of (some) properties.
     *
     * @param string $path
     */
    public function propPatchNodeUpdate($path, PropPatch $propPatch)
    {
        // This should trigger a 404 if the node doesn't exist.
        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof IProperties) {
            $node->propPatch($propPatch);
        }
    }

    /**
     * This method is called when properties are retrieved.
     *
     * Here we add all the default properties.
     */
    public function propFind(PropFind $propFind, INode $node)
    {
        $propFind->handle('{DAV:}getlastmodified', function () use ($node) {
            $lm = $node->getLastModified();
            if ($lm) {
                return new Xml\Property\GetLastModified($lm);
            }
        });

        if ($node instanceof IFile) {
            $propFind->handle('{DAV:}getcontentlength', [$node, 'getSize']);
            $propFind->handle('{DAV:}getetag', [$node, 'getETag']);
            $propFind->handle('{DAV:}getcontenttype', [$node, 'getContentType']);
        }

        if ($node instanceof IQuota) {
            $quotaInfo = null;
            $propFind->handle('{DAV:}quota-used-bytes', function () use (&$quotaInfo, $node) {
                $quotaInfo = $node->getQuotaInfo();

                return $quotaInfo[0];
            });
            $propFind->handle('{DAV:}quota-available-bytes', function () use (&$quotaInfo, $node) {
                if (!$quotaInfo) {
                    $quotaInfo = $node->getQuotaInfo();
                }

                return $quotaInfo[1];
            });
        }

        $propFind->handle('{DAV:}supported-report-set', function () use ($propFind) {
            $reports = [];
            foreach ($this->server->getPlugins() as $plugin) {
                $reports = array_merge($reports, $plugin->getSupportedReportSet($propFind->getPath()));
            }

            return new Xml\Property\SupportedReportSet($reports);
        });
        $propFind->handle('{DAV:}resourcetype', function () use ($node) {
            return new Xml\Property\ResourceType($this->server->getResourceTypeForNode($node));
        });
        $propFind->handle('{DAV:}supported-method-set', function () use ($propFind) {
            return new Xml\Property\SupportedMethodSet(
                $this->server->getAllowedMethods($propFind->getPath())
            );
        });
    }

    /**
     * Fetches properties for a node.
     *
     * This event is called a bit later, so plugins have a chance first to
     * populate the result.
     */
    public function propFindNode(PropFind $propFind, INode $node)
    {
        if ($node instanceof IProperties && $propertyNames = $propFind->get404Properties()) {
            $nodeProperties = $node->getProperties($propertyNames);
            foreach ($nodeProperties as $propertyName => $propertyValue) {
                $propFind->set($propertyName, $propertyValue, 200);
            }
        }
    }

    /**
     * This method is called when properties are retrieved.
     *
     * This specific handler is called very late in the process, because we
     * want other systems to first have a chance to handle the properties.
     */
    public function propFindLate(PropFind $propFind, INode $node)
    {
        $propFind->handle('{http://calendarserver.org/ns/}getctag', function () use ($propFind) {
            // If we already have a sync-token from the current propFind
            // request, we can re-use that.
            $val = $propFind->get('{http://sabredav.org/ns}sync-token');
            if ($val) {
                return $val;
            }

            $val = $propFind->get('{DAV:}sync-token');
            if ($val && is_scalar($val)) {
                return $val;
            }
            if ($val && $val instanceof Xml\Property\Href) {
                return substr($val->getHref(), strlen(Sync\Plugin::SYNCTOKEN_PREFIX));
            }

            // If we got here, the earlier two properties may simply not have
            // been part of the earlier request. We're going to fetch them.
            $result = $this->server->getProperties($propFind->getPath(), [
                '{http://sabredav.org/ns}sync-token',
                '{DAV:}sync-token',
            ]);

            if (isset($result['{http://sabredav.org/ns}sync-token'])) {
                return $result['{http://sabredav.org/ns}sync-token'];
            }
            if (isset($result['{DAV:}sync-token'])) {
                $val = $result['{DAV:}sync-token'];
                if (is_scalar($val)) {
                    return $val;
                } elseif ($val instanceof Xml\Property\Href) {
                    return substr($val->getHref(), strlen(Sync\Plugin::SYNCTOKEN_PREFIX));
                }
            }
        });
    }

    /**
     * Listens for exception events, and automatically logs them.
     *
     * @param Exception $e
     */
    public function exception($e)
    {
        $logLevel = \Psr\Log\LogLevel::CRITICAL;
        if ($e instanceof \Sabre\DAV\Exception) {
            // If it's a standard sabre/dav exception, it means we have a http
            // status code available.
            $code = $e->getHTTPCode();

            if ($code >= 400 && $code < 500) {
                // user error
                $logLevel = \Psr\Log\LogLevel::INFO;
            } else {
                // Server-side error. We mark it's as an error, but it's not
                // critical.
                $logLevel = \Psr\Log\LogLevel::ERROR;
            }
        }

        $this->server->getLogger()->log(
            $logLevel,
            'Uncaught exception',
            [
                'exception' => $e,
            ]
        );
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    public function getPluginInfo()
    {
        return [
            'name' => $this->getPluginName(),
            'description' => 'The Core plugin provides a lot of the basic functionality required by WebDAV, such as a default implementation for all HTTP and WebDAV methods.',
            'link' => null,
        ];
    }

    /**
     * Returns the processed ranges of the previously parsed range header.
     *
     * Each range consists of 2 numbers
     * The first number specifies the offset of the first byte in the range
     * The second number specifies the offset of the last byte in the range
     *
     * If the second offset is null, it should be treated as the offset of the last byte of the entity
     * If the first offset is null, the second offset should be used to retrieve the last x bytes of the entity
     *
     * If multiple ranges are specified, they will be preprocessed to avoid DOS attacks
     * Amount of ranges will be capped dependent on boundary size
     * Overlapping ranges will be capped to 2 per request
     * Ranges in descending order will be capped dependent on boundary size
     *
     * @throws Exception\PreconditionFailed           if ranges overlap or too many ranges are being requested
     * @throws Exception\RequestedRangeNotSatisfiable if an invalid range is requested
     *
     * @return array|null
     */
    private function preprocessRanges($rangeString, $nodeSize)
    {
        // create an array of all ranges
        // result example: [[0, 100], [200, 300]]
        $ranges = explode(',', $rangeString);

        // if an invalid range is encountered, its index will be saved in here
        // invalid ranges will be removed after the for loop
        // examples: start > end, '---200-300---', '-'
        $rangesToRemove = [];

        // loop over the array of ranges and do some basic checks and transforms
        for ($i = 0; $i < count($ranges); ++$i) {
            $ranges[$i] = explode('-', $ranges[$i]);

            // mark invalid ranges for removal
            // "---200-300---" would be considered a valid range by the regexp
            if (2 !== count($ranges[$i])) {
                $rangesToRemove[] = $i;
                continue;
            }

            // copy the ranges into temporary variables for comparisons
            $start = $ranges[$i][0];
            $end = $ranges[$i][1];

            // if neither start nor end value is defined, mark range for removal
            if ('' === $start && '' === $end) {
                $rangesToRemove[] = $i;
                continue;
            }

            // if start > filesize, mark for removal
            if ($start > $nodeSize) {
                $rangesToRemove[] = $i;
                continue;
            }

            // transform range parameters for special ranges immediately
            // this will make security checks later in this function much simpler
            if ('' === $start) {
                $ranges[$i][0] = max($nodeSize - intval($end), 0);
                $ranges[$i][1] = $nodeSize - 1;
            } elseif ('' === $end || $end > ($nodeSize - 1)) {
                $ranges[$i][0] = intval($start);
                $ranges[$i][1] = $nodeSize - 1;
            } else {
                $ranges[$i][0] = intval($start);
                $ranges[$i][1] = intval($end);
            }

            // if start > end, mark it for removal
            if ($ranges[$i][0] > $ranges[$i][1]) {
                $rangesToRemove[] = $i;
            }
        }

        // remove invalid ranges
        foreach ($rangesToRemove as $idx) {
            unset($ranges[$idx]);
        }

        // if no ranges are left at this point, return
        if (0 === count($ranges)) {
            throw new Exception\RequestedRangeNotSatisfiable('None of the provided ranges satisfy the requirements. Check out RFC 7233 to learn how to form range requests.');
        }

        // rearrange the array keys
        $ranges = array_values($ranges);

        /**
         * implement checks to prevent DOS attacks, according to rfc7233, 6.1.
         */

        // set up a sorted copy of the ranges array and define the boundary
        $sortedRanges = $ranges;
        sort($sortedRanges);

        $minRangeBoundary = $sortedRanges[0][0];
        $maxRangeBoundary = max(array_column($sortedRanges, 1));
        $rangeBoundary = $maxRangeBoundary - $minRangeBoundary;

        // check 1: limit the amount of ranges per request depending on range boundary
        // accept  512 ranges per request
        // rfc standard doesn't provide specification for the amount of ranges to be allowed per request
        if (count($ranges) > 512) {
            throw new Exception\RequestedRangeNotSatisfiable('Too many ranges in this request');
        }

        // check2: check for overlapping ranges and allow a maximum of 2
        // compare each start value with either the previous end value
        // or current max end range value, whichever is bigger
        $overlapCounter = 0;
        $maxEndValue = -1;

        foreach ($sortedRanges as $range) {
            // compare start value with the current maxEndValue
            if ($range[0] <= $maxEndValue) {
                ++$overlapCounter;

                if ($overlapCounter > 2) {
                    throw new Exception\RequestedRangeNotSatisfiable('Too many overlaps. Consider coalescing your overlapping ranges');
                }
            }

            // check if this ranges end value is larger than maxEndValue and update
            if ($range[1] > $maxEndValue) {
                $maxEndValue = $range[1];
            }
        }

        // check3: ranges should be requested in ascending order
        // it can be useful to do occasional descending requests, i.e. if a necessary
        // piece of information is located at the end of the file. however, if a range
        // consists of many unordered ranges, it can be indicative of a deliberate attack
        // limit descending ranges to 32 for boundaries < 1MB and 16 for boundaries larger than that
        $descendingStartCounter = 0;

        foreach ($ranges as $idx => $range) {
            // skip the first range
            if (0 === $idx) {
                continue;
            }

            if ($range[0] < $ranges[$idx - 1][0]) {
                ++$descendingStartCounter;
            }
        }

        if ($descendingStartCounter > 16) {
            throw new Exception\RequestedRangeNotSatisfiable('Too many unordered ranges in this request. Avoid listing ranges in descending order.');
        }

        return $ranges;
    }
}
