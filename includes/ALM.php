<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use IContextSource;
use MediaWiki;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class ALM {

    /**
     * @var ILoadBalancer
     */
    private $loadBalancer;

    /**
     * @var Title
     */
    private $title;

    /**
     * @var WANObjectCache
     */
    private $cache;

    /**
     * @var bool
     */
    private $exist;

    /**
     * @var string|null
     */
    private $currentLevel;

    /**
     * @var bool
     */
    private $wasPosted = false;

    /**
     * @var IContextSource
     */
    private $context;


    /**
     * @param Title $title
     * @param IContextSource $context
     */
    public function __construct( Title $title, IContextSource $context ) {
        $this->loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
        $this->title = $title;
        $this->exist = $this->title->getId() > 0;
        $this->context = $context;
        $this->wasPosted = $this->context->getRequest()->wasPosted();
    }
}