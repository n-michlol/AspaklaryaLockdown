<?php

namespace MediaWiki\Extension\PageLockdown\Services;

use MediaWiki\Cache\LinkCache;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkRendererFactory;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\TempUser\TempUserConfig;
use MediaWiki\User\TempUser\TempUserDetailsLookup;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserNameUtils;

class PageLockdownLinkRendererFactory extends LinkRendererFactory {

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

	private TempUserConfig $tempUserConfig;
	private TempUserDetailsLookup $tempUserDetailsLookup;
	private UserIdentityLookup $userIdentityLookup;
	private UserNameUtils $userNameUtils;

	/**
	 * @inheritDoc
	 */
	public function __construct(
		TitleFormatter $titleFormatter,
		LinkCache $linkCache,
		SpecialPageFactory $specialPageFactory,
		HookContainer $hookContainer,
		TempUserConfig $tempUserConfig,
		TempUserDetailsLookup $tempUserDetailsLookup,
		UserIdentityLookup $userIdentityLookup,
		UserNameUtils $userNameUtils
	) {
		$this->titleFormatter = $titleFormatter;
		$this->linkCache = $linkCache;
		$this->specialPageFactory = $specialPageFactory;
		$this->hookContainer = $hookContainer;
		$this->tempUserConfig = $tempUserConfig;
		$this->tempUserDetailsLookup = $tempUserDetailsLookup;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->userNameUtils = $userNameUtils;
		parent::__construct( $titleFormatter, $linkCache, $specialPageFactory, $hookContainer, $tempUserConfig, $tempUserDetailsLookup, $userIdentityLookup, $userNameUtils );
	}

	/**
	 * @inheritDoc
	 */
	public function create( array $options = [ 'renderForComment' => false ] ) {
		return new PageLockdownLinkRenderer(
			$this->titleFormatter, $this->linkCache, $this->specialPageFactory,
			$this->hookContainer, $this->tempUserConfig,
			$this->tempUserDetailsLookup, $this->userIdentityLookup,
			$this->userNameUtils,
			new ServiceOptions( LinkRenderer::CONSTRUCTOR_OPTIONS, $options )
		);
	}
}
