<?php

namespace Crawler\Jobs\Master;

use Crawler\Jobs\Worker\PreviewList;
use Crawler\Jobs\Worker\PreviewPost;

class PreviewRoute extends Route
{
    /**
     * The parsing job that will be dispatched.
     */
    protected $jobProgramMap = [
        PreviewList::class => 'preview-list',
        PreviewPost::class => 'preview-post',
    ];

}
