<?php


namespace MediaWiki\Extension\AspaklaryaLockDown\API;

use ApiBase;
use ApiPageSet;
use ApiQuery;
use ApiQueryGeneratorBase;
use DerivativeContext;
use FauxRequest;
use GenderCache;
use ILanguageConverter;
use Language;
use LinkBatch;
use LinkCache;
use MalformedTitleException;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\AspaklaryaLockDown\ALDBData;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\SpecialPage\SpecialPageFactory;
use RedirectSpecialArticle;
use Title;
use TitleFactory;
use Wikimedia\Rdbms\IResultWrapper;

class ALApiPageSet extends ApiPageSet {

    /**
     * Constructor flag: The new instance of ApiPageSet will ignore the 'generator=' parameter
     * @since 1.21
     */
    private const DISABLE_GENERATORS = 1;

    /** @var ApiBase used for getDb() call */
    private $mDbSource;

    /** @var array */
    private $mParams;

    /** @var bool */
    private $mResolveRedirects;

    /** @var bool */
    private $mConvertTitles;

    /** @var bool */
    private $mAllowGenerator;

    /** @var int[][] [ns][dbkey] => page_id or negative when missing */
    private $mAllPages = [];

    /** @var Title[] */
    private $mTitles = [];

    /** @var int[][] [ns][dbkey] => page_id or negative when missing */
    private $mGoodAndMissingPages = [];

    /** @var int[][] [ns][dbkey] => fake page_id */
    private $mMissingPages = [];

    /** @var Title[] */
    private $mMissingTitles = [];

    /** @var array[] [fake_page_id] => [ 'title' => $title, 'invalidreason' => $reason ] */
    private $mInvalidTitles = [];

    /** @var int[] */
    private $mMissingPageIDs = [];

    /** @var Title[] */
    private $mRedirectTitles = [];

    /** @var Title[] */
    private $mSpecialTitles = [];

    /** @var int[][] separate from mAllPages to avoid breaking getAllTitlesByNamespace() */
    private $mAllSpecials = [];

    /** @var string[] */
    private $mNormalizedTitles = [];

    /** @var string[] */
    private $mInterwikiTitles = [];

    /** @var Title[] */
    private $mPendingRedirectIDs = [];

    /** @var Title[][] [dbkey] => [ Title $from, Title $to ] */
    private $mPendingRedirectSpecialPages = [];

    /** @var Title[] */
    private $mResolvedRedirectTitles = [];

    /** @var string[] */
    private $mConvertedTitles = [];

    /** @var int[] Array of revID (int) => pageID (int) */
    private $mGoodRevIDs = [];

    /** @var int[] Array of revID (int) => pageID (int) */
    private $mLiveRevIDs = [];

    /** @var int[] Array of revID (int) => pageID (int) */
    private $mDeletedRevIDs = [];

    /** @var int[] */
    private $mMissingRevIDs = [];

    /** @var int */
    private $mFakePageId = -1;

    /** @var string */
    private $mCacheMode = 'public';

    /** @var int */
    private $mDefaultNamespace;

    /** @var Language */
    private $contentLanguage;

    /** @var LinkCache */
    private $linkCache;

    /** @var NamespaceInfo */
    private $namespaceInfo;

    /** @var GenderCache */
    private $genderCache;

    /** @var LinkBatchFactory */
    private $linkBatchFactory;

    /** @var TitleFactory */
    private $titleFactory;

    /** @var ILanguageConverter */
    private $languageConverter;

    /** @var SpecialPageFactory */
    private $specialPageFactory;

    /** @var WikiPageFactory */
    private $wikiPageFactory;

    /**
     * @param ApiBase $dbSource Module implementing getDB().
     *        Allows PageSet to reuse existing db connection from the shared state like ApiQuery.
     * @param int $flags Zero or more flags like DISABLE_GENERATORS
     * @param int $defaultNamespace The namespace to use if none is specified by a prefix.
     * @since 1.21 accepts $flags instead of two boolean values
     */
    public function __construct(ApiBase $dbSource, $flags = 0, $defaultNamespace = NS_MAIN) {
        parent::__construct($dbSource, $flags, $defaultNamespace);
        $this->mDbSource = $dbSource;
        $this->mAllowGenerator = ($flags & self::DISABLE_GENERATORS) == 0;
        $this->mDefaultNamespace = $defaultNamespace;

        $this->mParams = $this->extractRequestParams();
        $this->mResolveRedirects = $this->mParams['redirects'];
        $this->mConvertTitles = $this->mParams['converttitles'];

        // Needs service injection - T283314
        $services = MediaWikiServices::getInstance();
        $this->contentLanguage = $services->getContentLanguage();
        $this->linkCache = $services->getLinkCache();
        $this->namespaceInfo = $services->getNamespaceInfo();
        $this->genderCache = $services->getGenderCache();
        $this->linkBatchFactory = $services->getLinkBatchFactory();
        $this->titleFactory = $services->getTitleFactory();
        $this->languageConverter = $services->getLanguageConverterFactory()
            ->getLanguageConverter($this->contentLanguage);
        $this->specialPageFactory = $services->getSpecialPageFactory();
        $this->wikiPageFactory = $services->getWikiPageFactory();
    }

    /**
     * Populate the PageSet from the request parameters.
     */
    public function execute() {
        $this->executeInternal(false);
    }

    /**
     * Populate the PageSet from the request parameters.
     * @param bool $isDryRun If true, instantiates generator, but only to mark
     *    relevant parameters as used
     */
    private function executeInternal($isDryRun) {
        $generatorName = $this->mAllowGenerator ? $this->mParams['generator'] : null;
        if (isset($generatorName)) {
            $dbSource = $this->mDbSource;
            if (!$dbSource instanceof ApiQuery) {
                // If the parent container of this pageset is not ApiQuery, we must create it to run generator
                $dbSource = $this->getMain()->getModuleManager()->getModule('query');
            }
            $generator = $dbSource->getModuleManager()->getModule($generatorName, null, true);
            if ($generator === null) {
                $this->dieWithError(['apierror-badgenerator-unknown', $generatorName], 'badgenerator');
            }
            if (!$generator instanceof ApiQueryGeneratorBase) {
                $this->dieWithError(['apierror-badgenerator-notgenerator', $generatorName], 'badgenerator');
            }
            // Create a temporary pageset to store generator's output,
            // add any additional fields generator may need, and execute pageset to populate titles/pageids
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable T240141
            $tmpPageSet = new ALApiPageSet($dbSource, self::DISABLE_GENERATORS); // aspaklarya_lockdown change
            $generator->setGeneratorMode($tmpPageSet);
            $this->mCacheMode = $generator->getCacheMode($generator->extractRequestParams());

            if (!$isDryRun) {
                $generator->requestExtraData($tmpPageSet);
            }
            $tmpPageSet->executeInternal($isDryRun);

            // populate this pageset with the generator output
            if (!$isDryRun) {
                $generator->executeGenerator($this);

                // @phan-suppress-next-line PhanTypeMismatchArgumentNullable T240141
                $this->getHookRunner()->onAPIQueryGeneratorAfterExecute($generator, $this);
            } else {
                // Prevent warnings from being reported on these parameters
                $main = $this->getMain();
                foreach ($generator->extractRequestParams() as $paramName => $param) {
                    $main->markParamsUsed($generator->encodeParamName($paramName));
                }
            }

            if (!$isDryRun) {
                $this->resolvePendingRedirects();
            }
        } else {
            // Only one of the titles/pageids/revids is allowed at the same time
            $dataSource = null;
            if (isset($this->mParams['titles'])) {
                $dataSource = 'titles';
            }
            if (isset($this->mParams['pageids'])) {
                if (isset($dataSource)) {
                    $this->dieWithError(
                        [
                            'apierror-invalidparammix-cannotusewith',
                            $this->encodeParamName('pageids'),
                            $this->encodeParamName($dataSource)
                        ],
                        'multisource'
                    );
                }
                $dataSource = 'pageids';
            }
            if (isset($this->mParams['revids'])) {
                if (isset($dataSource)) {
                    $this->dieWithError(
                        [
                            'apierror-invalidparammix-cannotusewith',
                            $this->encodeParamName('revids'),
                            $this->encodeParamName($dataSource)
                        ],
                        'multisource'
                    );
                }
                $dataSource = 'revids';
            }

            if (!$isDryRun) {
                // Populate page information with the original user input
                switch ($dataSource) {
                    case 'titles':
                        $this->initFromTitles($this->mParams['titles']);
                        break;
                    case 'pageids':
                        $this->initFromPageIds($this->mParams['pageids']);
                        break;
                    case 'revids':
                        if ($this->mResolveRedirects) {
                            $this->addWarning('apiwarn-redirectsandrevids');
                        }
                        $this->mResolveRedirects = false;
                        $this->initFromRevIDs($this->mParams['revids']);
                        break;
                    default:
                        // Do nothing - some queries do not need any of the data sources.
                        break;
                }
            }
        }
    }

    /**
     * Given an array of title strings, convert them into Title objects.
     * Alternatively, an array of Title objects may be given.
     * This method validates access rights for the title,
     * and appends normalization values to the output.
     *
     * @param string[]|LinkTarget[]|PageReference[] $titles
     * @return LinkBatch
     */
    private function processTitlesArray($titles) {
        $linkBatch = $this->linkBatchFactory->newLinkBatch();

        /** @var Title[] $titleObjects */
        $titleObjects = [];
        foreach ($titles as $index => $title) {
            if (is_string($title)) {
                try {
                    /** @var Title $titleObj */
                    $titleObj = $this->titleFactory->newFromTextThrow($title, $this->mDefaultNamespace);
                } catch (MalformedTitleException $ex) {
                    // Handle invalid titles gracefully
                    if (!isset($this->mAllPages[0][$title])) {
                        $this->mAllPages[0][$title] = $this->mFakePageId;
                        $this->mInvalidTitles[$this->mFakePageId] = [
                            'title' => $title,
                            'invalidreason' => $this->getErrorFormatter()->formatException($ex, ['bc' => true]),
                        ];
                        $this->mFakePageId--;
                    }
                    continue; // There's nothing else we can do
                }
            } elseif ($title instanceof LinkTarget) {
                $titleObj = $this->titleFactory->castFromLinkTarget($title);
            } else {
                $titleObj = $this->titleFactory->castFromPageReference($title);
            }

            $titleObjects[$index] = $titleObj;
        }

        // Get gender information
        $this->genderCache->doTitlesArray($titleObjects, __METHOD__);

        foreach ($titleObjects as $index => $titleObj) {
            $title = is_string($titles[$index]) ? $titles[$index] : false;
            $unconvertedTitle = $titleObj->getPrefixedText();
            $titleWasConverted = false;
            if ($titleObj->isExternal()) {
                // This title is an interwiki link.
                $this->mInterwikiTitles[$unconvertedTitle] = $titleObj->getInterwiki();
            } else {
                // Variants checking
                if (
                    $this->mConvertTitles
                    && $this->languageConverter->hasVariants()
                    && !$titleObj->exists()
                ) {
                    // ILanguageConverter::findVariantLink will modify titleText and
                    // titleObj into the canonical variant if possible
                    $titleText = $title !== false ? $title : $titleObj->getPrefixedText();
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable castFrom does not return null here
                    $this->languageConverter->findVariantLink($titleText, $titleObj);
                    $titleWasConverted = $unconvertedTitle !== $titleObj->getPrefixedText();
                }

                if ($titleObj->getNamespace() < 0) {
                    // Handle Special and Media pages
                    $titleObj = $titleObj->fixSpecialName();
                    $ns = $titleObj->getNamespace();
                    $dbkey = $titleObj->getDBkey();
                    if (!isset($this->mAllSpecials[$ns][$dbkey])) {
                        $this->mAllSpecials[$ns][$dbkey] = $this->mFakePageId;
                        $target = null;
                        if ($ns === NS_SPECIAL && $this->mResolveRedirects) {
                            $special = $this->specialPageFactory->getPage($dbkey);
                            if ($special instanceof RedirectSpecialArticle) {
                                // Only RedirectSpecialArticle is intended to redirect to an article, other kinds of
                                // RedirectSpecialPage are probably applying weird URL parameters we don't want to
                                // handle.
                                $context = new DerivativeContext($this);
                                $context->setTitle($titleObj);
                                $context->setRequest(new FauxRequest());
                                $special->setContext($context);
                                list( /* $alias */, $subpage) = $this->specialPageFactory->resolveAlias($dbkey);
                                $target = $special->getRedirect($subpage);
                            }
                        }
                        if ($target) {
                            $this->mPendingRedirectSpecialPages[$dbkey] = [$titleObj, $target];
                        } else {
                            $this->mSpecialTitles[$this->mFakePageId] = $titleObj;
                            $this->mFakePageId--;
                        }
                    }
                } else {
                    // Regular page
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable castFrom does not return null here
                    $linkBatch->addObj($titleObj);
                }
            }

            // Make sure we remember the original title that was
            // given to us. This way the caller can correlate new
            // titles with the originally requested when e.g. the
            // namespace is localized or the capitalization is
            // different
            if ($titleWasConverted) {
                $this->mConvertedTitles[$unconvertedTitle] = $titleObj->getPrefixedText();
                // In this case the page can't be Special.
                if ($title !== false && $title !== $unconvertedTitle) {
                    $this->mNormalizedTitles[$title] = $unconvertedTitle;
                }
            } elseif ($title !== false && $title !== $titleObj->getPrefixedText()) {
                $this->mNormalizedTitles[$title] = $titleObj->getPrefixedText();
            }
        }

        return $linkBatch;
    }

    /**
     * This method populates internal variables with page information
     * based on the given array of title strings.
     *
     * Steps:
     * #1 For each title, get data from `page` table
     * #2 If page was not found in the DB, store it as missing
     *
     * Additionally, when resolving redirects:
     * #3 If no more redirects left, stop.
     * #4 For each redirect, get its target from the `redirect` table.
     * #5 Substitute the original LinkBatch object with the new list
     * #6 Repeat from step #1
     *
     * @param string[]|LinkTarget[]|PageReference[] $titles
     */
    private function initFromTitles($titles) {
        // Get validated and normalized title objects
        $linkBatch = $this->processTitlesArray($titles);
        if ($linkBatch->isEmpty()) {
            // There might be special-page redirects
            $this->resolvePendingRedirects();
            return;
        }

        $db = $this->getDB();

        // Get pageIDs data from the `page` table
        $res = $db->newSelectQueryBuilder()
            ->select($this->getPageTableFields())
            ->from('page')
            ->where($linkBatch->constructSet('page', $db))
            ->caller(__METHOD__)
            ->fetchResultSet();

        // Hack: get the ns:titles stored in [ ns => [ titles ] ] format
        $this->initFromQueryResult($res, $linkBatch->data, true); // process Titles

        // Resolve any found redirects
        $this->resolvePendingRedirects();
    }

    /**
     * Iterate through the result of the query on 'page' table,
     * and for each row create and store title object and save any extra fields requested.
     * @param IResultWrapper|null $res DB Query result
     * @param array|null &$remaining Array of either pageID or ns/title elements (optional).
     *        If given, any missing items will go to $mMissingPageIDs and $mMissingTitles
     * @param bool|null $processTitles Must be provided together with $remaining.
     *        If true, treat $remaining as an array of [ns][title]
     *        If false, treat it as an array of [pageIDs]
     */
    private function initFromQueryResult($res, &$remaining = null, $processTitles = null) {
        if ($remaining !== null && $processTitles === null) {
            ApiBase::dieDebug(__METHOD__, 'Missing $processTitles parameter when $remaining is provided');
        }

        $usernames = [];
        if ($res) {
            foreach ($res as $row) {
                $pageId = (int)$row->page_id;

                // Remove found page from the list of remaining items
                if ($remaining) {
                    if ($processTitles) {
                        unset($remaining[$row->page_namespace][$row->page_title]);
                    } else {
                        unset($remaining[$pageId]);
                    }
                }

                // Store any extra fields requested by modules
                $this->processDbRow($row);

                // Need gender information
                if ($this->namespaceInfo->hasGenderDistinction($row->page_namespace)) {
                    $usernames[] = $row->page_title;
                }
            }
        }

        if ($remaining) {
            // Any items left in the $remaining list are added as missing
            if ($processTitles) {
                // The remaining titles in $remaining are non-existent pages
                foreach ($remaining as $ns => $dbkeys) {
                    foreach (array_keys($dbkeys) as $dbkey) {
                        $title = $this->titleFactory->makeTitle($ns, $dbkey);
                        $this->linkCache->addBadLinkObj($title);
                        $this->mAllPages[$ns][$dbkey] = $this->mFakePageId;
                        $this->mMissingPages[$ns][$dbkey] = $this->mFakePageId;
                        $this->mGoodAndMissingPages[$ns][$dbkey] = $this->mFakePageId;
                        $this->mMissingTitles[$this->mFakePageId] = $title;
                        $this->mFakePageId--;
                        $this->mTitles[] = $title;

                        // need gender information
                        if ($this->namespaceInfo->hasGenderDistinction($ns)) {
                            $usernames[] = $dbkey;
                        }
                    }
                }
            } else {
                // The remaining pageids do not exist
                if (!$this->mMissingPageIDs) {
                    $this->mMissingPageIDs = array_keys($remaining);
                } else {
                    $this->mMissingPageIDs = array_merge($this->mMissingPageIDs, array_keys($remaining));
                }
            }
        }

        // Get gender information
        $this->genderCache->doQuery($usernames, __METHOD__);
    }

    /**
     * Does the same as initFromTitles(), but is based on page IDs instead
     * @param int[] $pageids
     * @param bool $filterIds Whether the IDs need filtering
     */
    private function initFromPageIds($pageids, $filterIds = true) {
        if (!$pageids) {
            return;
        }

        $pageids = array_map('intval', $pageids); // paranoia
        $remaining = array_fill_keys($pageids, true);

        if ($filterIds) {
            $pageids = $this->filterIDs([['page', 'page_id']], $pageids);
        }

        $res = null;
        if (!empty($pageids)) {
            $db = $this->getDB();

            // Get pageIDs data from the `page` table
            $res = $db->newSelectQueryBuilder()
                ->select($this->getPageTableFields())
                ->from('page')
                ->where(['page_id' => $pageids])
                ->caller(__METHOD__)
                ->fetchResultSet();
        }

        $this->initFromQueryResult($res, $remaining, false); // process PageIDs

        // Resolve any found redirects
        $this->resolvePendingRedirects();
    }

    /**
     * Resolve any redirects in the result if redirect resolution was
     * requested. This function is called repeatedly until all redirects
     * have been resolved.
     */
    private function resolvePendingRedirects() {
        if ($this->mResolveRedirects) {
            $db = $this->getDB();

            // Repeat until all redirects have been resolved
            // The infinite loop is prevented by keeping all known pages in $this->mAllPages
            while ($this->mPendingRedirectIDs || $this->mPendingRedirectSpecialPages) {
                // Resolve redirects by querying the pagelinks table, and repeat the process
                // Create a new linkBatch object for the next pass
                $linkBatch = $this->loadRedirectTargets();

                if ($linkBatch->isEmpty()) {
                    break;
                }

                $set = $linkBatch->constructSet('page', $db);
                if ($set === false) {
                    break;
                }

                // Get pageIDs data from the `page` table
                $res = $db->newSelectQueryBuilder()
                    ->select($this->getPageTableFields())
                    ->from('page')
                    ->where($set)
                    ->caller(__METHOD__)
                    ->fetchResultSet();

                // Hack: get the ns:titles stored in [ns => array(titles)] format
                $this->initFromQueryResult($res, $linkBatch->data, true);
            }
        }
    }

    /**
     * Get the targets of the pending redirects from the database
     *
     * Also creates entries in the redirect table for redirects that don't
     * have one.
     * @return LinkBatch
     */
    private function loadRedirectTargets() {
        $titlesToResolve = [];
        $db = $this->getDB();

        if ($this->mPendingRedirectIDs) {
            $res = $db->newSelectQueryBuilder()
                ->select([
                    'rd_from',
                    'rd_namespace',
                    'rd_fragment',
                    'rd_interwiki',
                    'rd_title'
                ])
                ->from('redirect')
                ->where(['rd_from' => array_keys($this->mPendingRedirectIDs)])
                ->caller(__METHOD__)
                ->fetchResultSet();

            foreach ($res as $row) {
                $rdfrom = (int)$row->rd_from;
                $from = $this->mPendingRedirectIDs[$rdfrom]->getPrefixedText();
                $to = $this->titleFactory->makeTitle(
                    $row->rd_namespace,
                    $row->rd_title,
                    $row->rd_fragment ?? '',
                    $row->rd_interwiki ?? ''
                );
                $this->mResolvedRedirectTitles[$from] = $this->mPendingRedirectIDs[$rdfrom];
                unset($this->mPendingRedirectIDs[$rdfrom]);
                if ($to->isExternal()) {
                    $this->mInterwikiTitles[$to->getPrefixedText()] = $to->getInterwiki();
                } elseif (
                    !isset($this->mAllPages[$to->getNamespace()][$to->getDBkey()])
                    && !($this->mConvertTitles && isset($this->mConvertedTitles[$to->getPrefixedText()]))
                ) {
                    $titlesToResolve[] = $to;
                }
                $this->mRedirectTitles[$from] = $to;
            }

            if ($this->mPendingRedirectIDs) {
                // We found pages that aren't in the redirect table
                // Add them
                foreach ($this->mPendingRedirectIDs as $id => $title) {
                    $page = $this->wikiPageFactory->newFromTitle($title);
                    $rt = $page->insertRedirect();
                    if (!$rt) {
                        // What the hell. Let's just ignore this
                        continue;
                    }
                    if ($rt->isExternal()) {
                        $this->mInterwikiTitles[$rt->getPrefixedText()] = $rt->getInterwiki();
                    } elseif (!isset($this->mAllPages[$rt->getNamespace()][$rt->getDBkey()])) {
                        $titlesToResolve[] = $rt;
                    }
                    $from = $title->getPrefixedText();
                    $this->mResolvedRedirectTitles[$from] = $title;
                    $this->mRedirectTitles[$from] = $rt;
                    unset($this->mPendingRedirectIDs[$id]);
                }
            }
        }

        if ($this->mPendingRedirectSpecialPages) {
            foreach ($this->mPendingRedirectSpecialPages as [$from, $to]) {
                /** @var Title $from */
                $fromKey = $from->getPrefixedText();
                $this->mResolvedRedirectTitles[$fromKey] = $from;
                $this->mRedirectTitles[$fromKey] = $to;
                if ($to->isExternal()) {
                    $this->mInterwikiTitles[$to->getPrefixedText()] = $to->getInterwiki();
                } elseif (!isset($this->mAllPages[$to->getNamespace()][$to->getDBkey()])) {
                    $titlesToResolve[] = $to;
                }
            }
            $this->mPendingRedirectSpecialPages = [];

            // Set private caching since we don't know what criteria the
            // special pages used to decide on these redirects.
            $this->mCacheMode = 'private';
        }

        return $this->processTitlesArray($titlesToResolve);
    }


    /**
     * Populate this PageSet from a list of revision IDs
     * @param int[] $revIDs Array of revision IDs
     */
    public function populateFromRevisionIDs($revIDs) {
        $this->initFromRevIDs($revIDs);
    }

    /**
     * Does the same as initFromTitles(), but is based on revision IDs
     * instead
     * @param int[] $revids Array of revision IDs
     */
    private function initFromRevIDs($revids) {
        if (!$revids) {
            return;
        }

        $revids = array_map('intval', $revids); // paranoia
        $db = $this->getDB();
        $pageids = [];
        $remaining = array_fill_keys($revids, true);

        $revids = $this->filterIDs([['revision', 'rev_id'], ['archive', 'ar_rev_id']], $revids);
        $goodRemaining = array_fill_keys($revids, true);

        if ($revids) {
            $fields = ['rev_id', 'rev_page'];

            // Get pageIDs data from the `page` table
            $res = $db->newSelectQueryBuilder()
                ->select($fields)
                ->from('page')
                ->where(['rev_id' => $revids])
                ->join('revision', null, ['rev_page = page_id'])
                ->caller(__METHOD__)
                ->fetchResultSet();
            foreach ($res as $row) {
                $revid = (int)$row->rev_id;
                $pageid = (int)$row->rev_page;
                $this->mGoodRevIDs[$revid] = $pageid;
                $this->mLiveRevIDs[$revid] = $pageid;
                $pageids[$pageid] = '';
                unset($remaining[$revid]);
                unset($goodRemaining[$revid]);
            }
        }

        // Populate all the page information
        $this->initFromPageIds(array_keys($pageids), false);

        // If the user can see deleted revisions, pull out the corresponding
        // titles from the archive table and include them too. We ignore
        // ar_page_id because deleted revisions are tied by title, not page_id.
        if (
            $goodRemaining &&
            $this->getAuthority()->isAllowed('deletedhistory')
        ) {

            $res = $db->newSelectQueryBuilder()
                ->select(['ar_rev_id', 'ar_namespace', 'ar_title'])
                ->from('archive')
                ->where(['ar_rev_id' => array_keys($goodRemaining)])
                ->caller(__METHOD__)
                ->fetchResultSet();

            $titles = [];
            foreach ($res as $row) {
                $revid = (int)$row->ar_rev_id;
                $titles[$revid] = $this->titleFactory->makeTitle($row->ar_namespace, $row->ar_title);
                unset($remaining[$revid]);
            }

            $this->initFromTitles($titles);

            foreach ($titles as $revid => $title) {
                $ns = $title->getNamespace();
                $dbkey = $title->getDBkey();

                // Handle converted titles
                if (
                    !isset($this->mAllPages[$ns][$dbkey]) &&
                    isset($this->mConvertedTitles[$title->getPrefixedText()])
                ) {
                    $title = $this->titleFactory->newFromText($this->mConvertedTitles[$title->getPrefixedText()]);
                    $ns = $title->getNamespace();
                    $dbkey = $title->getDBkey();
                }

                if (isset($this->mAllPages[$ns][$dbkey])) {
                    $this->mGoodRevIDs[$revid] = $this->mAllPages[$ns][$dbkey];
                    $this->mDeletedRevIDs[$revid] = $this->mAllPages[$ns][$dbkey];
                } else {
                    $remaining[$revid] = true;
                }
            }
        }
        // aspaklarya_locked code
        if (!$this->getAuthority()->isAllowed('aspaklarya-read-locked')) {
            foreach ($this->mGoodRevIDs as $revid => $pageid) {
                $locked = ALDBData::isRevisionLocked($revid);
                if ($locked) {
                    unset($this->mGoodRevIDs[$revid]);
                    $this->mDeletedRevIDs[$revid] = $pageid;
                }
            }
        }
        $this->mMissingRevIDs = array_keys($remaining);
    }
}
