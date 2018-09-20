<?php

namespace Crawler\Services\Parser\Tools;

use URL\Normalizer;

class UrlManipulator
{
    /**
     * The URL.
     *
     * @var string
     */
    private $url;

    /**
     * The Normalizer instance.
     *
     * @var \URL\Normalizer
     */
    private $normalizer;

    /**
     * Create a new instance of this class.
     *
     * @param $url
     */
    public function __construct($url = null)
    {
        $this->url = $url;
        $this->normalizer = new Normalizer(null, true, true);
    }

    /**
     * Set the url.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Normalize the url.
     *
     * @return $this
     */
    public function normalize()
    {
        $this->normalizer->setUrl($this->url);
        $this->url = $this->normalizer->normalize();

        return $this;
    }

    /**
     * Returns the url hashed.
     *
     * @return string
     */
    public function getHashed()
    {
        return sha1($this->url);
    }

    /**
     * Returns the url.
     *
     * @return string
     */
    public function get()
    {
        return $this->url;
    }
}
