<?php

namespace Crawler\Services\Parser;

use Bowtie\Grawler\Client;
use Bowtie\Grawler\Tools\ExtractorHelper;
use Bowtie\Grawler\Tools\MediaHelper;
use Bowtie\Grawler\Tools\MalformedUrlHelper;
use Bowtie\Grawler\Tools\ImageSizeHelper;
use Crawler\SDO\Media;
use Crawler\SDO\Post;
use Crawler\SDO\Provider;
use Crawler\Services\Parser\Tools\Timer;
use Crawler\Services\Parser\Exceptions\ExtractorsNotAvailableException;
use Smarty\Nodes\Crawler\SmartyDomain;
use Smarty\Nodes\Crawler\SmartyExtractor;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client as GuzzleClient;
use Crawler\Services\Parser\Tools\UrlManipulator;
use andreskrey\Readability\Readability;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;

class DataPageParser
{
    use ExtractorHelper, MediaHelper, MalformedUrlHelper, ImageSizeHelper;
    
    /** 
     * Guzzle Client connect timeout (seconds) 
     */ 
    const CONNECT_TIMEOUT = 5;

    /** 
     * Guzzle Client request timeout (seconds) 
     */ 
    const REQUEST_TIMEOUT = 20;

    /**
     * Min image width in pixels
     */
    const MIN_IMAGE_WIDTH = 100;

    /**
     * Min image height in pixels
     */
    const MIN_IMAGE_HEIGHT = 100;

    /**
     * The original list page where the data page came from.
     *
     * @var string
     */
    private $domain;

    /**
     * The data page to parse.
     *
     * @var string
     */
    private $post;

    /**
     * Collection of SmartyExtractor containing elements paths.
     *
     * @var \Smarty\Nodes\Crawler\SmartyExtractor
     */
    private $extractors;

    /**
     * Instance of SmartyExtractor containing elements paths.
     *
     * @var \Smarty\Nodes\Crawler\SmartyExtractor
     */
    private $extractor;

    /**
     * @var \Bowtie\Grawler\Grawler
     */
    protected $grawler;

    /**
     * @var string
     */
    protected $post_url;

    /**
     * @var bool 
     */
    protected $recrawled;

    /**
     * DataPageParser constructor.
     * @param Post $post
     * @param $domain
     * @param bool $recrawled
     */
    public function __construct(Post $post, $domain, $recrawled = false)
    {
        $this->post = $post;
        $this->domain = $domain;
        $this->post_url = $this->post->getOriginUrl();
        $this->extractors = $this->detectExtractors();
        $this->urlManipulator = new UrlManipulator;
        $this->recrawled = $recrawled;
    }

    /**
     * Parse the data.
     *
     * @return array
     * @throws \Exception
     */
    public function parse()
    {
        $data = [
            'title' => '',
            'body' => '',
            'images' => []
        ];

        $guzzleClient = new GuzzleClient(['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::REQUEST_TIMEOUT]);
        $client = new Client();
        $client->setClient($guzzleClient);
        $client->agent('Googlebot/2.1');

        try {
            $this->grawler = $client->download($this->post_url);
        } catch (\Exception $exception){
            Log::warning($exception->getMessage());
            return null;
        }

        //set baseHref (received url) to Post when its url has redirect
        $baseHref = $this->grawler->getBaseHref();
        if(strpos($this->post->getOriginUrl(), parse_url($baseHref)['host']) === false ){
            $this->post->setOriginUrl($this->urlManipulator->setUrl($baseHref)->normalize()->get());
        }

        if($this->extractors){
            try{
                $data = $this->parseWithExtractor();
                $this->post->setError($data['errors']);
            } catch (\Exception $exception){
                Log::warning($exception->getMessage());
            }
        }

        if(!$data['title'] || !$data['body']){
            $data = $this->parseWithReadability($data); //parse using readability
            $parsedWith = 'readability';
        } else {
            $parsedWith = 'extractors';
        }

        $provider = (new Provider())->setId($this->domain ? $this->domain->getId() : null)
                                    ->setName($this->domain
                                        ? $this->domain->getName()
                                        : parse_url('http://' . str_replace(['https://', 'http://'], '', $this->post_url), PHP_URL_HOST));

        $this->post ->setTitle($data['title'])
                    ->setBody($data['body'])
                    ->setMedia($data['media'])
                    ->setParsedWith($parsedWith)
                    ->setProvider($provider);

        $this->post->getWebCrawlerSource() && $this->post->setRecrawled($this->recrawled); //set recrawled attr to webCrawlerSource

        return $this->post->toArray();
    }

    /**
     * @return array|null|string
     */
    protected function parseWithExtractor()
    {
        $dataValid = false;

        // Loop into matched extractors.
        foreach ($this->extractors as $extractor) {
            $this->extractor = $extractor;

            try{
                $data['title'] = $this->grawler->title($this->extractor->getTitlePath());
                $data['body'] = $this->grawler->body($this->extractor->getBodyPath());
            }catch (\Exception $exception){
                Log::warning($exception->getMessage());
                continue;
            }

            // Only for http://nna-leb.gov.lb decode title and body
            if(strpos($this->post_url, 'nna-leb.gov.lb') !== false){
                $data = $this->decodeData($data);
            }

            // Flush data if invalid, break if valid.
            if ($dataValid = $this->checkData($data, $extractor)){
                break;
            }
        }

        $itemErrors = (object)[];
        // Return data using facebook api if no data found
        if(!$dataValid) {
            if(!$data['title']){
                $itemErrors->title = 'Title Selector';
            }
            if(!$data['body']){
                $itemErrors->body = 'Body Selector';
            }
        }
        // Extracting images media
        $images = [];
        if($this->extractor['take_image']){
            $images = $this->collectImages($this->extractor->getImagesPath());
            // if(empty($images)){
            //     $itemErrors->image = 'Image Selector';
            // }
        }

        // Extracting video media
        $videos = [];
        if($video_path = $this->extractor->getVideosPath()){
            $videos = $this->collectVideo($video_path);
            // if(empty($videos)){
            //     $itemErrors->video = 'Video Selector';
            // }
        }

        // Extracting audio media
        $audios = [];
        if($audio_path = $this->extractor->getAudiosPath()){
            $audios = $this->collectAudio($audio_path);
            // if(empty($audios)){
            //     $itemErrors->audio = 'Audio Selector';
            // }
        }

        if($this->extractor['exclude_text'])
            $data = $this->excludeFromData($this->extractor['exclude_text'], $data);

        $data['media'] = array_merge(
            $images,
            $videos,
            $audios
        );

        $data['errors'] = $itemErrors;

        return $data;
    }

    /**
     * @param $data
     * @return array
     */
    private function decodeData($data){
        foreach($data as &$string){
            if (is_array($string)) {
                continue;
            }
            $string = mb_detect_encoding($string, 'UTF-8', true) ? utf8_decode($string) : $string;
            $string = iconv('WINDOWS-1256', 'UTF-8', $string);
            $string = trim($string, " \t\n\r\0\x0B\x3D");
        }
        return $data;
    }

    /**
     * Try to get data using readability library
     * @param $data
     * @return mixed
     */
    private function parseWithReadability($data)
    {
        $readability = new Readability(new Configuration());

        try {
            $readability->parse($this->grawler->getHtml());
        } catch (ParseException $e) {
            Log::info('Readability error processing text: '.$e->getMessage());
        }

        $data['title'] = $readability->getTitle();
        $data['body'] = preg_replace('/<[^>]*>/', '', $readability->getContent()); // remove tags
        $data['image'] = $readability->getImage();

        $images = $data['image'] ? [$data['image']] : [];
        $data['media'] = $this->collectReadabilityImages($images);

        return $data;
    }

    /**
     * Log.
     *
     * @param $message
     * @param int $success
     *
     * @throws \Exception
     */
    private function log($message, $success = 0)
    {
        if(env('ENABLE_LOGGING')) {
            Log::notice($message, [
                'success'       => $success,
                'post_url'      => $this->post_url,
                'app_name'      => 'Crawler2.0',
                'worker_name'   => 'Services\Parser\DataPageParser',
                'process_time'  => $this->timer->result(),
            ]);
        }
    }
}
