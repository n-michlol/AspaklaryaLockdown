<?php

namespace MediaWiki\Extension\AspaklaryaLockDown;

use LinkCache;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkRendererFactory;
use MediaWiki\SpecialPage\SpecialPageFactory;
use TitleFormatter;

class ALLinkRendererFactory extends LinkRendererFactory {

    /**
	 * @var TitleFormatter
	 */
	private $titleFormatter;

	/**
	 * @var LinkCache
	 */
	private $linkCache;

	/**
	 * @var HookContainer
	 */
	private $hookContainer;

	/**
	 * @var SpecialPageFactory
	 */
	private $specialPageFactory;

    /**
	 * @inheritDoc
	 */
	public function __construct(
		TitleFormatter $titleFormatter,
		LinkCache $linkCache,
		SpecialPageFactory $specialPageFactory,
		HookContainer $hookContainer
	) {
		$this->titleFormatter = $titleFormatter;
		$this->linkCache = $linkCache;
		$this->specialPageFactory = $specialPageFactory;
		$this->hookContainer = $hookContainer;
        parent::__construct($titleFormatter, $linkCache, $specialPageFactory, $hookContainer);
	}

    /**
	 * @inheritDoc
	 */
	public function create( array $options = [ 'renderForComment' => false ] ) {
		return new ALLinkRenderer(
			$this->titleFormatter, $this->linkCache, $this->specialPageFactory,
			$this->hookContainer,
			new ServiceOptions( LinkRenderer::CONSTRUCTOR_OPTIONS, $options )
		);
	}
}