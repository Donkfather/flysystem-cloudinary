<?php

namespace Donkfather\Filesystem\Cloudinary;

use Cloudinary;
use Cloudinary\Api as Api;
use Cloudinary\Uploader;
use Exception;
use Illuminate\Support\Arr;
use JD\Cloudder\Facades\Cloudder;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;

/**
 *
 */
class CloudinaryAdapter implements AdapterInterface
{
    /**
     * @var Cloudinary\Api
     */
    protected $api;
    /**
     * @var Disk config
     */
    protected $diskOptions;
    /**
     * Cloudinary does not suppory visibility - all is public
     */
    use NotSupportingVisibilityTrait;

    /**
     * Constructor
     * Sets configuration, and dependency Cloudinary Api.
     * @param array $options Cloudinary configuration
     */
    public function __construct(array $options)
    {
        Cloudinary::config(array_merge([
            //            'api_key'       => env('CLOUDINARY_API_KEY'),
            //            'api_secret'    => env('CLOUDINARY_API_SECRET'),
            //            'cloud_name'    => env('CLOUDINARY_CLOUD_NAME'),
        ], $options));
        $this->diskOptions = $options;
        $this->api = new Api;
    }

    /**
     * Update a file.
     * Cloudinary has no specific update method. Overwrite instead.
     *
     * @param string $path
     * @param string $contents
     * @param Config $options Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $options)
    {
        return $this->write($path, $contents, $options);
    }

    /**
     * Write a new file.
     * Create temporary stream with content.
     * Pass to writeStream.
     *
     * @param string $path
     * @param string $contents
     * @param Config $options Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $options)
    {
        // 1. Save to temporary local file -- it will be destroyed automatically
        $tempfile = tmpfile();
        fwrite($tempfile, $contents);
        // 2. Use Cloudinary to send
        $uploaded_metadata = $this->writeStream($path, $tempfile, $options);
        return $uploaded_metadata;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $options Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $options)
    {
        $resourceMetadata = stream_get_meta_data($resource);
        $path = $this->prefixPath($path);
        $options = $this->mergeOptions($options);
        $uploaded_metadata = Cloudder::upload($resourceMetadata['uri'], $path, $options,
            Arr::get($options, 'tags', []));

        return $uploaded_metadata;
    }

    /**
     * @param $path
     * @return string
     */
    private function prefixPath($path): string
    {
        return ltrim(rtrim(Arr::get($this->diskOptions, 'path_prefix', ''), '/') . '/' . ltrim($path, '/'), '/');
    }

    /**
     * @param $options
     * @return array
     */
    private function mergeOptions($options)
    {
        $disk_config = Arr::only($this->diskOptions, ['secure', 'upload_preset', 'tags']);

        return array_merge($disk_config, Arr::get($options, 'cloudinary', []));
    }

    /**
     * Update a file using a stream.
     * Cloudinary has no specific update method. Overwrite instead.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $options Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $options)
    {
        return $this->writeStream($this->prefixPath($path), $resource, $options);
    }

    /**
     * Rename a file.
     * Paths without extensions.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $pathInfo = pathinfo($this->prefixPath($path));
        if ($pathInfo['dirname'] != '.') {
            $pathRemote = $pathInfo['dirname'] . '/' . $pathInfo['filename'];
        } else {
            $pathRemote = $pathInfo['filename'];
        }
        $newpathinfo = pathinfo($this->prefixPath($newpath));
        if ($newpathinfo['dirname'] != '.') {
            $newpath_remote = $newpathinfo['dirname'] . '/' . $newpathinfo['filename'];
        } else {
            $newpath_remote = $newpathinfo['filename'];
        }
        $result = Uploader::rename($pathRemote, $newpath_remote);
        return $result['public_id'] == $newpathinfo['filename'];
    }

    /**
     * Copy a file.
     * Copy content from existing url.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $url = cloudinary_url_internal($this->prefixPath($path));
        $newpath = $this->prefixPath($newpath);

        $result = Uploader::upload($url, ['public_id' => $newpath]);
        return is_array($result) ? $result['public_id'] == $newpath : false;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $result = Uploader::destroy($this->prefixPath($path), ['invalidate' => true]);
        return is_array($result) ? $result['result'] == 'ok' : false;
    }

    /**
     * Delete a directory.
     * Delete Files using directory as a prefix.
     *
     * @param string $dirname
     *
     * @return bool
     * @throws \Cloudinary\Api\GeneralError
     */
    public function deleteDir($dirname)
    {
        $response = $this->api->delete_resources_by_prefix($this->prefixPath($dirname));
        return true;
    }

    /**
     * Create a directory.
     * Cloudinary does not realy embrace the concept of "directories".
     * Those are more like a part of a name / public_id.
     * Just keep swimming.
     *
     * @param string $dirname directory name
     * @param Config $options
     *
     * @return array|false
     */
    public function createDir($dirname, Config $options)
    {
        return ['path' => $dirname];
    }

    /**
     * Check whether a file exists.
     * Using url to check response headers.
     * Maybe I should use api resource?
     *
     * substr(get_headers(cloudinary_url_internal($path))[0], -6 ) == '200 OK';
     * need to test that for spead
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        try {
            $this->api->resource($this->prefixPath($path));
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $contents = file_get_contents(cloudinary_url($this->prefixPath($path)));
        return compact('contents', 'path');
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        try {
            $stream = fopen(cloudinary_url($this->prefixPath($path)), 'r');
        } catch (Exception $e) {
            return false;
        }
        return compact('stream', 'path');
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     * @throws \Cloudinary\Api\GeneralError
     */
    public function listContents($directory = '', $recursive = false)
    {
        // get resources array
        $resources = ((array)$this->api->resources([
            'type'   => 'upload',
            'prefix' => $directory,
        ])['resources']);
        // parse resourses
        foreach ($resources as $i => $resource) {
            $resources[$i] = $this->prepareResourceMetadata($resource);
        }
        return $resources;
    }

    /**
     * Prepare apropriate metadata for resource metadata given from cloudinary.
     * @param  array $resource
     * @return array
     */
    protected function prepareResourceMetadata($resource)
    {
        $resource['type'] = 'file';
        $resource['path'] = $resource['public_id'];
        $resource = array_merge($resource, $this->prepareSize($resource));
        $resource = array_merge($resource, $this->prepareTimestamp($resource));
        $resource = array_merge($resource, $this->prepareMimetype($resource));
        return $resource;
    }

    /**
     * prepare size response
     * @param array $resource
     * @return array
     */
    protected function prepareSize($resource)
    {
        $size = $resource['bytes'];
        return compact('size');
    }

    /**
     * prepare timestpamp response
     * @param  array $resource
     * @return array
     */
    protected function prepareTimestamp($resource)
    {
        $timestamp = strtotime($resource['created_at']);
        return compact('timestamp');
    }

    /**
     * prepare timestpamp response
     * @param  array $resource
     * @return array
     */
    protected function prepareMimetype($resource)
    {
        // hack
        $mimetype = $resource['resource_type'] . '/' . $resource['format'];
        $mimetype = str_replace('jpg', 'jpeg', $mimetype); // hack to a hack
        return compact('mimetype');
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     * @throws \Cloudinary\Api\GeneralError
     */
    public function getMetadata($path)
    {
        return $this->prepareResourceMetadata($this->getResource($this->prefixPath($path)));
    }

    /**
     * Get Resource data
     * @param  string $path
     * @return array
     * @throws \Cloudinary\Api\GeneralError
     */
    public function getResource($path)
    {
        return (array)$this->api->resource($this->prefixPath($path));
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     * @throws \Cloudinary\Api\GeneralError
     */
    public function getSize($path)
    {
        return $this->prepareSize($this->getResource($path));
    }

    /**
     * Get the mimetype of a file.
     * Actually I don't think cloudinary supports mimetypes.
     * Or I am just stupid and cannot find it.
     * This is an ugly hack.
     *
     * @param string $path
     *
     * @return array|false
     * @throws \Cloudinary\Api\GeneralError
     */
    public function getMimetype($path)
    {
        return $this->prepareMimetype($this->getResource($path));
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     * @throws \Cloudinary\Api\GeneralError
     */
    public function getTimestamp($path)
    {
        return $this->prepareTimestamp($this->getResource($path));
    }

    /**
     * @param       $path
     * @param array $options
     * @return mixed
     */
    public function getUrl($path, array $options = [])
    {
        $options = $this->mergeOptions($options);
        $method = Arr::get($options, 'secure', true) ? 'secureShow' : 'show';

        return Cloudder::$method($this->prefixPath($path), $options);
    }
}
