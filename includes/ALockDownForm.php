<?php

/**
 * Page lockdown
 *
 * Copyright © 2005 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Extension\AspaklaryaLockDown\ALDBData as AspaklaryaLockDownALDBData;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\User\UserIdentity;
use MediaWiki\Watchlist\WatchlistManager;

/**
 * Handles the page lockdown UI and backend
 */
class LockDownForm
{
	/** @var array A map of action to restriction level, from request or default */
	protected $mRestrictions = [];

	/** @var string The custom/additional lockdown reason */
	protected $mReason = '';

	/** @var string The reason selected from the list, blank for other/additional */
	protected $mReasonSelection = '';

	/** 
	 * @var array Map of action to "other" expiry time. Used in preference to mExpirySelection. 
	 * @todo FIXME: This is not used anywhere
	 * */
	protected $mExpiry = [];

	/**
	 * @var array Map of action to value selected in expiry drop-down list.
	 * Will be set to 'othertime' whenever mExpiry is set.
	 * @todo FIXME: This is not used anywhere
	 */
	protected $mExpirySelection = [];

	/** @var PermissionStatus Permissions errors for the lockdown action */
	protected $mPermStatus;

	/** 
	 * @var array Types (i.e. actions) for which levels can be selected 
	 * @todo FIXME: This is not used anywhere
	 * */
	protected $mApplicableTypes = [];

	/** 
	 * @var array Map of action to the expiry time of the existing protection 
	 * @todo FIXME: This is not used anywhere
	 * */
	protected $mExistingExpiry = [];

	/** @var Article */
	protected $mArticle;

	/** @var Title */
	protected $mTitle;

	/** @var bool */
	protected $disabled;

	/** @var array */
	protected $disabledAttrib;

	/** @var IContextSource */
	private $mContext;

	/** @var WebRequest */
	private $mRequest;

	/** @var Authority */
	private $mPerformer;

	/** @var Language */
	private $mLang;

	/** @var OutputPage */
	private $mOut;

	/** @var PermissionManager */
	private $permManager;

	/**
	 * @var WatchlistManager
	 */
	private $watchlistManager;

	/** 
	 * @var HookRunner 
	 * @todo FIXME: This is not used anywhere, we probably dont need to add hookes here
	 * */
	private $hookRunner;

	/** 
	 * @var RestrictionStore 
	 * @todo FIXME: This probably needs to get changed or rewritten
	 * */
	private $restrictionStore;

	/** @var TitleFormatter */
	private $titleFormatter;

	public function __construct(WikiPage $article, IContextSource $context)
	{
		// Set instance variables.
		$this->mArticle = $article;
		$this->mTitle = $article->getTitle();
		$this->mContext = $context;
		$this->mRequest = $this->mContext->getRequest();
		$this->mPerformer = $this->mContext->getAuthority();
		$this->mOut = $this->mContext->getOutput();
		$this->mLang = $this->mContext->getLanguage();

		$services = MediaWikiServices::getInstance();
		$this->permManager = $services->getPermissionManager();
		// @todo FIXME: Remove it after all calls to it are removed
		$this->hookRunner = new HookRunner($services->getHookContainer());
		$this->watchlistManager = $services->getWatchlistManager();
		$this->titleFormatter = $services->getTitleFormatter();
		/**
		 * @todo FIXME: After rewriting Restrictionstore, we need to change this
		 */
		$this->restrictionStore = $services->getRestrictionStore();
		/**
		 * @todo FIXME: Remove after all refrences are removed
		 */
		$this->mApplicableTypes = $this->restrictionStore->listApplicableRestrictionTypes($this->mTitle);

		// Check if the form should be disabled.
		// If it is, the form will be available in read-only to show levels.
		$this->mPermStatus = PermissionStatus::newEmpty();
		if ($this->mRequest->wasPosted()) {
			$this->mPerformer->authorizeWrite('aspaklarya_lockdown', $this->mTitle, $this->mPermStatus);
		} else {
			$this->mPerformer->authorizeRead('aspaklarya_lockdown', $this->mTitle, $this->mPermStatus);
		}
		$readOnlyMode = $services->getReadOnlyMode();
		if ($readOnlyMode->isReadOnly()) {
			$this->mPermStatus->fatal('readonlytext', $readOnlyMode->getReason());
		}
		$this->disabled = !$this->mPermStatus->isGood();
		$this->disabledAttrib = $this->disabled ? ['disabled' => 'disabled'] : [];

		$this->loadData();
	}

	/**
	 * Loads the current state of lockdown into the object.
	 */
	private function loadData()
	{
		$levels = $this->permManager->getNamespaceRestrictionLevels(
			$this->mTitle->getNamespace(),
			$this->mPerformer->getUser()
		);

		$this->mReason = $this->mRequest->getText('aLockdown-reason');
		$this->mReasonSelection = $this->mRequest->getText('aLockdownReasonSelection');

		// @todo FIXME: I need to understand this loop and what it does and how to set it up for our needs
		foreach ($this->mApplicableTypes as $action) {
			// @todo FIXME: This form currently requires individual selections,
			// but the db allows multiples separated by commas.

			// Pull the actual restriction from the DB
			$this->mRestrictions[$action] = implode(
				'',
				$this->restrictionStore->getRestrictions($this->mTitle, $action)
			);

			$val = $this->mRequest->getVal("mwProtect-level-$action");
			if (isset($val) && in_array($val, $levels)) {
				$this->mRestrictions[$action] = $val;
			}
		}
	}


	/**
	 * Main entry point for action=protect and action=unprotect
	 */
	public function execute()
	{
		if (
			$this->permManager->getNamespaceRestrictionLevels(
				$this->mTitle->getNamespace()
			) === ['']
		) {
			throw new ErrorPageError('protect-badnamespace-title', 'protect-badnamespace-text');
		}

		if ($this->mRequest->wasPosted()) {
			if ($this->save()) {
				$q = $this->mArticle->getPage()->isRedirect() ? 'redirect=no' : '';
				$this->mOut->redirect($this->mTitle->getFullURL($q));
			}
		} else {
			$this->show();
		}
	}

	/**
	 * Show the input form with optional error message
	 *
	 * @param string|string[]|null $err Error message or null if there's no error
	 * @phan-param string|non-empty-array|null $err
	 */
	private function show($err = null)
	{
		$out = $this->mOut;
		$out->setRobotPolicy('noindex,nofollow');
		$out->addBacklinkSubtitle($this->mTitle);

		if (is_array($err)) {
			$out->addHTML(Html::errorBox($out->msg(...$err)->parse()));
		} elseif (is_string($err)) {
			$out->addHTML(Html::errorBox($err));
		}

		if ($this->mApplicableTypes === []) {
			// No restriction types available for the current title
			// this might happen if an extension alters the available types
			$out->setPageTitle($this->mContext->msg(
				'lockdown-norestrictiontypes-title',
				$this->mTitle->getPrefixedText()
			));
			$out->addWikiTextAsInterface(
				$this->mContext->msg('lockdown-norestrictiontypes-text')->plain()
			);

			// Show the log in case lockdown was possible once
			$this->showLogExtract();
			// return as there isn't anything else we can do
			return;
		}

		# Show an appropriate message if the user isn't allowed or able to change
		# the lockdown settings at this time
		if ($this->disabled) {
			$out->setPageTitle(
				$this->mContext->msg(
					'lockdown-title-notallowed',
					$this->mTitle->getPrefixedText()
				)
			);
			$out->addWikiTextAsInterface(
				$out->formatPermissionStatus($this->mPermStatus, 'aspaklarya_lockdown')
			);
		} else {
			$out->setPageTitle(
				$this->mContext->msg('aspaklarya_lockdown-title', $this->mTitle->getPrefixedText())
			);
			$out->addWikiMsg(
				'aspaklarya_lockdown-text',
				wfEscapeWikiText($this->mTitle->getPrefixedText())
			);
		}

		$out->addHTML($this->buildForm());
		$this->showLogExtract();
	}

	/**
	 * Save submitted protection form
	 *
	 * @return bool Success
	 */
	private function save()
	{
		# Permission check!
		if ($this->disabled) {
			$this->show();
			return false;
		}

		$token = $this->mRequest->getVal('wpEditToken');
		$legacyUser = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromAuthority($this->mPerformer);
		if (!$legacyUser->matchEditToken($token, ['aspaklarya_lockdown', $this->mTitle->getPrefixedDBkey()])) {
			$this->show(['sessionfailure']);
			return false;
		}

		# Create reason string. Use list and/or custom string.
		$reasonstr = $this->mReasonSelection;
		if ($reasonstr != 'other' && $this->mReason != '') {
			// Entry from drop down menu + additional comment
			$reasonstr .= $this->mContext->msg('colon-separator')->text() . $this->mReason;
		} elseif ($reasonstr == 'other') {
			$reasonstr = $this->mReason;
		}


		// @todo FIXME: This should be localised
		$status = $this->doUpdateRestrictions(
			"edit", // @todo FIXME: This should be a variable
			$reasonstr,
			$this->mPerformer->getUser()
		);

		if (!$status->isOK()) {
			$this->show($this->mOut->parseInlineAsInterface(
				$status->getWikiText(false, false, $this->mLang)
			));
			return false;
		}

		$this->watchlistManager->setWatch(
			$this->mRequest->getCheck('aLockdownWatch'),
			$this->mPerformer,
			$this->mTitle
		);

		return true;
	}

	/**
	 * Update the article's restriction field, and leave a log entry.
	 * This works for protection both existing and non-existing pages.
	 *
	 * @param string $limit edit|read|create|""
	 * @param string $reason
	 * @param UserIdentity $user The user updating the restrictions
	 * @param string[] $tags Change tags to add to the pages and protection log entries
	 *   ($user should be able to add the specified tags before this is called)
	 * @return Status Status object; if action is taken, $status->value is the log_id of the
	 *   protection log entry.
	 */
	public function doUpdateRestrictions(
		string $limit,
		$reason,
		UserIdentity $user,
		$tags = []
	) {
		$readOnlyMode = MediaWikiServices::getInstance()->getReadOnlyMode();
		if ($readOnlyMode->isReadOnly()) {
			return Status::newFatal(wfMessage('readonlytext', $readOnlyMode->getReason()));
		}
		$mPage = $this->mArticle->getPage();

		$mPage->loadPageData('fromdbmaster');
		$id = $mPage->getId();
		$connection = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY);
		$restriction = $connection->newSelectQueryBuilder()
			->select(["al_page_id", "al_page_read"])
			->from(AspaklaryaLockDownALDBData::getPagesTableName())
			->where(["al_page_id" => $id])
			->caller(__METHOD__)
			->fetchRow();


		// @todo: Same limitations as described in ProtectionForm.php (line 37);
		// we expect a single selection, but the schema allows otherwise.
		$isProtected = false;
		$protect = false;
		$changed = false;

		$dbw = wfGetDB(DB_PRIMARY);

		if ($limit !== $restriction || ($limit === '' && $restriction !== false)) {
			$changed = true;
		}

		// If nothing has changed, do nothing
		if (!$changed) {
			return Status::newGood();
		}

		if (!$protect) { // No protection at all means unprotection
			$revCommentMsg = 'unprotectedarticle-comment';
			$logAction = 'unprotect';
		} elseif ($isProtected) {
			$revCommentMsg = 'modifiedarticleprotection-comment';
			$logAction = 'modify';
		} else {
			$revCommentMsg = 'protectedarticle-comment';
			$logAction = 'protect';
		}

		$logRelationsValues = [];
		$logRelationsField = null;
		$logParamsDetails = [];

		// Null revision (used for change tag insertion)
		$nullRevisionRecord = null;

		if ($id) { // Protection of existing page
			$legacyUser = MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity($user);
			// if ( !$mPage->getHookRunner()->onArticleProtect( $this, $legacyUser, $limit, $reason ) ) {
			// 	return Status::newGood();
			// }

			

			// insert null revision to identify the page protection change as edit summary
			$latest = $mPage->getLatest();
			$nullRevisionRecord = $mPage->insertNullProtectionRevision(
				$revCommentMsg,
				$limit,
				$expiry,
				$cascade,
				$reason,
				$user
			);

			if ($nullRevisionRecord === null) {
				return Status::newFatal('no-null-revision', $this->mTitle->getPrefixedText());
			}

			$logRelationsField = 'pr_id';

			// T214035: Avoid deadlock on MySQL.
			// Do a DELETE by primary key (pr_id) for any existing protection rows.
			// On MySQL and derivatives, unconditionally deleting by page ID (pr_page) would.
			// place a gap lock if there are no matching rows. This can deadlock when another
			// thread modifies protection settings for page IDs in the same gap.
			$existingProtectionIds = $dbw->selectFieldValues(
				'page_restrictions',
				'pr_id',
				[
					'pr_page' => $id,
					'pr_type' => array_map('strval', array_keys($limit))
				],
				__METHOD__
			);

			if ($existingProtectionIds) {
				$dbw->delete(
					'page_restrictions',
					['pr_id' => $existingProtectionIds],
					__METHOD__
				);
			}

			// Update restrictions table
			foreach ($limit as $action => $restrictions) {
				if ($restrictions != '') {
					$cascadeValue = ($cascade && $action == 'edit') ? 1 : 0;
					$dbw->insert(
						'page_restrictions',
						[
							'pr_page' => $id,
							'pr_type' => $action,
							'pr_level' => $restrictions,
							'pr_cascade' => $cascadeValue,
							'pr_expiry' => $dbw->encodeExpiry($expiry[$action])
						],
						__METHOD__
					);
					$logRelationsValues[] = $dbw->insertId();
					$logParamsDetails[] = [
						'type' => $action,
						'level' => $restrictions,
						'expiry' => $expiry[$action],
						'cascade' => (bool)$cascadeValue,
					];
				}
			}

			// $mPage->getHookRunner()->onRevisionFromEditComplete(
			// 	$mPage, $nullRevisionRecord, $latest, $user, $tags );

			// $mPage->getHookRunner()->onArticleProtectComplete( $this, $legacyUser, $limit, $reason );
		} else { // Protection of non-existing page (also known as "title protection")
			// Cascade protection is meaningless in this case
			$cascade = false;

			if ($limit['create'] != '') {
				$commentFields = CommentStore::getStore()->insert($dbw, 'pt_reason', $reason);
				$dbw->replace(
					'protected_titles',
					[['pt_namespace', 'pt_title']],
					[
						'pt_namespace' => $this->mTitle->getNamespace(),
						'pt_title' => $this->mTitle->getDBkey(),
						'pt_create_perm' => $limit['create'],
						'pt_timestamp' => $dbw->timestamp(),
						'pt_expiry' => $dbw->encodeExpiry($expiry['create']),
						'pt_user' => $user->getId(),
					] + $commentFields,
					__METHOD__
				);
				$logParamsDetails[] = [
					'type' => 'create',
					'level' => $limit['create'],
					'expiry' => $expiry['create'],
				];
			} else {
				$dbw->delete(
					'protected_titles',
					[
						'pt_namespace' => $this->mTitle->getNamespace(),
						'pt_title' => $this->mTitle->getDBkey()
					],
					__METHOD__
				);
			}
		}

		$this->mTitle->flushRestrictions();
		InfoAction::invalidateCache($this->mTitle);

		if ($logAction == 'unprotect') {
			$params = [];
		} else {
			$protectDescriptionLog = $mPage->protectDescriptionLog($limit, $expiry);
			$params = [
				'4::description' => $protectDescriptionLog, // parameter for IRC
				'5:bool:cascade' => $cascade,
				'details' => $logParamsDetails, // parameter for localize and api
			];
		}

		// Update the protection log
		$logEntry = new ManualLogEntry('protect', $logAction);
		$logEntry->setTarget($this->mTitle);
		$logEntry->setComment($reason);
		$logEntry->setPerformer($user);
		$logEntry->setParameters($params);
		if ($nullRevisionRecord !== null) {
			$logEntry->setAssociatedRevId($nullRevisionRecord->getId());
		}
		$logEntry->addTags($tags);
		if ($logRelationsField !== null && count($logRelationsValues)) {
			$logEntry->setRelations([$logRelationsField => $logRelationsValues]);
		}
		$logId = $logEntry->insert();
		$logEntry->publish($logId);

		return Status::newGood($logId);
	}


	/**
	 * Build the input form
	 *
	 * @return string HTML form
	 */
	private function buildForm()
	{
		$this->mOut->enableOOUI();
		$out = '';
		$fields = [];
		if (!$this->disabled) {
			$this->mOut->addModules('mediawiki.action.protect');
			$this->mOut->addModuleStyles('mediawiki.action.styles');
		}
		$scExpiryOptions = $this->mContext->msg('protect-expiry-options')->inContentLanguage()->text();
		$levels = $this->permManager->getNamespaceRestrictionLevels(
			$this->mTitle->getNamespace(),
			$this->disabled ? null : $this->mPerformer->getUser()
		);

		// Not all languages have V_x <-> N_x relation
		foreach ($this->mRestrictions as $action => $selected) {
			// Messages:
			// restriction-edit, restriction-move, restriction-create, restriction-upload
			$section = 'restriction-' . $action;
			$id = 'mwProtect-level-' . $action;
			$options = [];
			foreach ($levels as $key) {
				$options[$this->getOptionLabel($key)] = $key;
			}

			$fields[$id] = [
				'type' => 'select',
				'name' => $id,
				'default' => $selected,
				'id' => $id,
				'size' => count($levels),
				'options' => $options,
				'disabled' => $this->disabled,
				'section' => $section,
			];

			$expiryOptions = [];

			if ($this->mExistingExpiry[$action]) {
				if ($this->mExistingExpiry[$action] == 'infinity') {
					$existingExpiryMessage = $this->mContext->msg('protect-existing-expiry-infinity');
				} else {
					$existingExpiryMessage = $this->mContext->msg('protect-existing-expiry')
						->dateTimeParams($this->mExistingExpiry[$action])
						->dateParams($this->mExistingExpiry[$action])
						->timeParams($this->mExistingExpiry[$action]);
				}
				$expiryOptions[$existingExpiryMessage->text()] = 'existing';
			}

			$expiryOptions[$this->mContext->msg('protect-othertime-op')->text()] = 'othertime';

			$expiryOptions = array_merge($expiryOptions, XmlSelect::parseOptionsMessage($scExpiryOptions));

			# Add expiry dropdown
			$fields["wpProtectExpirySelection-$action"] = [
				'type' => 'select',
				'name' => "wpProtectExpirySelection-$action",
				'id' => "mwProtectExpirySelection-$action",
				'tabindex' => '2',
				'disabled' => $this->disabled,
				'label' => $this->mContext->msg('protectexpiry')->text(),
				'options' => $expiryOptions,
				'default' => $this->mExpirySelection[$action],
				'section' => $section,
			];

			# Add custom expiry field
			if (!$this->disabled) {
				$fields["mwProtect-expiry-$action"] = [
					'type' => 'text',
					'label' => $this->mContext->msg('protect-othertime')->text(),
					'name' => "mwProtect-expiry-$action",
					'id' => "mwProtect-$action-expires",
					'size' => 50,
					'default' => $this->mExpiry[$action],
					'disabled' => $this->disabled,
					'section' => $section,
				];
			}
		}

		# Give extensions a chance to add items to the form
		$hookFormRaw = '';
		$hookFormOptions = [];

		$this->hookRunner->onProtectionForm__buildForm($this->mArticle, $hookFormRaw);
		$this->hookRunner->onProtectionFormAddFormFields($this->mArticle, $hookFormOptions);

		# Merge forms added from addFormFields
		$fields = array_merge($fields, $hookFormOptions);

		# Add raw sections added in buildForm
		if ($hookFormRaw) {
			$fields['rawinfo'] = [
				'type' => 'info',
				'default' => $hookFormRaw,
				'raw' => true,
				'section' => 'restriction-blank'
			];
		}

		/* 
		@todo FIXME: This is not used anywhere
		# JavaScript will add another row with a value-chaining checkbox
		if ( $this->mTitle->exists() ) {
			$fields['mwProtect-cascade'] = [
				'type' => 'check',
				'label' => $this->mContext->msg( 'protect-cascade' )->text(),
				'id' => 'mwProtect-cascade',
				'name' => 'mwProtect-cascade',
				'default' => $this->mCascade,
				'disabled' => $this->disabled,
			];
		} */

		# Add manual and custom reason field/selects as well as submit
		if (!$this->disabled) {
			// HTML maxlength uses "UTF-16 code units", which means that characters outside BMP
			// (e.g. emojis) count for two each. This limit is overridden in JS to instead count
			// Unicode codepoints.
			// Subtract arbitrary 75 to leave some space for the autogenerated null edit's summary
			// and other texts chosen by dropdown menus on this page.
			$maxlength = CommentStore::COMMENT_CHARACTER_LIMIT - 75;
			$fields['aLockdownReasonSelection'] = [
				'type' => 'select',
				'cssclass' => 'aLockdown-reason',
				'label' => $this->mContext->msg('protectcomment')->text(),
				'tabindex' => 4,
				'id' => 'aLockdownReasonSelection',
				'name' => 'aLockdownReasonSelection',
				'flatlist' => true,
				'options' => Xml::listDropDownOptions(
					$this->mContext->msg('protect-dropdown')->inContentLanguage()->text(),
					['other' => $this->mContext->msg('protect-otherreason-op')->inContentLanguage()->text()]
				),
				'default' => $this->mReasonSelection,
			];
			$fields['aLockdown-reason'] = [
				'type' => 'text',
				'id' => 'aLockdown-reason',
				'label' => $this->mContext->msg('protect-otherreason')->text(),
				'name' => 'aLockdown-reason',
				'size' => 60,
				'maxlength' => $maxlength,
				'default' => $this->mReason,
			];
			# Disallow watching if user is not logged in
			if ($this->mPerformer->getUser()->isRegistered()) {
				$fields['aLockdownWatch'] = [
					'type' => 'check',
					'id' => 'aLockdownWatch',
					'label' => $this->mContext->msg('watchthis')->text(),
					'name' => 'aLockdownWatch',
					'default' => (
						$this->watchlistManager->isWatched($this->mPerformer, $this->mTitle)
						|| MediaWikiServices::getInstance()->getUserOptionsLookup()->getOption(
							$this->mPerformer->getUser(),
							'watchdefault'
						)
					),
				];
			}
		}

		if ($this->mPerformer->isAllowed('editinterface')) {
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			$link = $linkRenderer->makeKnownLink(
				$this->mContext->msg('protect-dropdown')->inContentLanguage()->getTitle(),
				$this->mContext->msg('protect-edit-reasonlist')->text(),
				[],
				['action' => 'edit']
			);
			$out .= '<p class="mw-protect-editreasons">' . $link . '</p>';
		}

		$htmlForm = HTMLForm::factory('ooui', $fields, $this->mContext);
		$htmlForm
			->setMethod('post')
			->setId('mw-Protect-Form')
			->setTableId('mw-protect-table2')
			->setAction($this->mTitle->getLocalURL('action=protect'))
			->setSubmitID('mw-Protect-submit')
			->setSubmitTextMsg('confirm')
			->setTokenSalt(['protect', $this->mTitle->getPrefixedDBkey()])
			->suppressDefaultSubmit($this->disabled)
			->setWrapperLegendMsg('protect-legend')
			->prepareForm();

		return $htmlForm->getHTML(false) . $out;
	}

	/**
	 * Prepare the label for a protection selector option
	 *
	 * @param string $permission Permission required
	 * @return string
	 */
	private function getOptionLabel($permission)
	{
		if ($permission == '') {
			return $this->mContext->msg('protect-default')->text();
		} else {
			// Messages: protect-level-autoconfirmed, protect-level-sysop
			$msg = $this->mContext->msg("protect-level-{$permission}");
			if ($msg->exists()) {
				return $msg->text();
			}
			return $this->mContext->msg('protect-fallback', $permission)->text();
		}
	}

	/**
	 * Show protection long extracts for this page
	 */
	private function showLogExtract()
	{
		# Show relevant lines from the protection log:
		$protectLogPage = new LogPage('protect');
		$this->mOut->addHTML(Xml::element('h2', null, $protectLogPage->getName()->text()));
		/** @phan-suppress-next-line PhanTypeMismatchPropertyByRef */
		LogEventsList::showLogExtract($this->mOut, 'protect', $this->mTitle);
		# Let extensions add other relevant log extracts
		$this->hookRunner->onProtectionForm__showLogExtract($this->mArticle, $this->mOut);
	}
}
