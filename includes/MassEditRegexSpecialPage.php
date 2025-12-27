<?php

/**
 * Allow users in the Bot group to edit many articles in one go by applying
 * regular expressions to a list of pages.
 *
 * @file
 * @ingroup SpecialPage
 *
 * @link https://www.mediawiki.org/wiki/Extension:MassEditRegex Documentation
 *
 * @author Adam Nielsen <malvineous@shikadi.net>
 * @copyright Copyright © 2009,2013 Adam Nielsen
 * @license GPL-2.0-or-later
 */

use MediaWiki\Category\Category;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/// Maximum number of pages/diffs to display when previewing the changes
define( 'MER_MAX_PREVIEW_DIFFS', 20 );

/// Maximum number of pages to edit.
define( 'MER_MAX_EXECUTE_PAGES', 1000 );

/** Main class that define a new special page */
class MassEditRegexSpecialPage extends SpecialPage {
	/**
	 * @var string[]|null User-supplied page titles
	 */
	private $aPageList;

	/**
	 * @var string|null Type of titles (categories, backlinks, etc.)
	 */
	private $strPageListType;

	/**
	 * @var string|null Match regex from form
	 */
	private $strMatch;

	/**
	 * @var string|null Substitution regex from form
	 */
	private $strReplace;

	/**
	 * @var bool|null Is the client-side checkbox ticked?
	 */
	private $isClientSide;

	/**
	 * @var Skin|null
	 */
	private $sk;

	/**
	 * @var \MassEditRegex|null
	 */
	private $massEditRegex;

	function __construct() {
		parent::__construct( 'MassEditRegex', 'masseditregex' );
	}

	public function doesWrites() {
		return true;
	}

	/// Perform the regex process.

	/**
	 * @param bool $isPreview
	 *   true to generate diffs, false to perform page edits.
	 */
	public function perform( $isPreview ) {
		$out = $this->getOutput();
		$getuser = $this->getUser();
		$pageCountLimit = $isPreview ? MER_MAX_PREVIEW_DIFFS : MER_MAX_EXECUTE_PAGES;
		$errors = [];

		$htmlDiff = '';

		if ( $isPreview ) {
			$this->massEditRegex->getDiffEngine()->showDiffStyle();
		} else {
			$out->addHTML( '<ul>' );
		}

		$iArticleCount = 0;
		try {
			foreach ( $this->aPageList as $pageTitle ) {
				$titleArray = [];
				switch ( $this->strPageListType ) {
					case 'pagenames': // Can do this in one hit
						$t = Title::newFromText( $pageTitle );
						if ( !$t || !$this->editPage( $t, $isPreview, $htmlDiff ) ) {
							$errors[] = $this->msg( 'masseditregex-page-not-exists',
								$pageTitle )->escaped();
						}
						$iArticleCount++;
						break;

					case 'pagename-prefixes':
						$prefixSearch = new StringPrefixSearch;
						$titles = $prefixSearch->search( $pageTitle,
							$pageCountLimit - $iArticleCount );
						if ( !$titles ) {
							$errors[] = $this->msg( 'masseditregex-exprnomatch',
								$pageTitle )->escaped();
							$iArticleCount++;
							break;
						}

						foreach ( $titles as $title ) {
							$t = Title::newFromText( $title );
							if ( !$t ) {
								$errors[] = $this->msg( 'masseditregex-page-not-exists', $title )->escaped();
							} else {
								$titleArray[] = $t;
							}
						}
						break;

					case 'categories':
						$cat = Category::newFromName( $pageTitle );
						if ( $cat === false ) {
							$errors[] = $this->msg( 'masseditregex-page-not-exists',
								$pageTitle )->escaped();
							break;
						}
						// T163056 - don't query the category members if we already have more results than the limit
						// allows
						$catMembersToQuery = $pageCountLimit - $iArticleCount;
						if ( $catMembersToQuery > 0 ) {
							$titleArray = $cat->getMembers( $catMembersToQuery );
						}
						break;

					case 'backlinks':
						$t = Title::newFromText( $pageTitle );
						if ( !$t ) {
							if ( $isPreview ) {
								$errors[] = $this->msg( 'masseditregex-page-not-exists',
									$pageTitle )->escaped();
							}
							break;
						}
						$blc = MediaWikiServices::getInstance()->getBacklinkCacheFactory()
							->getBacklinkCache( $t );
						if ( $t->getNamespace() == NS_TEMPLATE ) {
							// Backlinks for Template pages are in a different table
							$table = 'templatelinks';
						} else {
							$table = 'pagelinks';
						}
						$pageIdentityArray = $blc->getLinkPages( $table, false, false,
							$pageCountLimit - $iArticleCount );
						foreach ( $pageIdentityArray as $pageIdentity ) {
							$titleArray[] = Title::castFromPageIdentity( $pageIdentity );
						}
						break;
				}

				// If the above switch produced an array of pages, run through them now
				foreach ( $titleArray as $target ) {
					if ( !$this->editPage( $target, $isPreview, $htmlDiff ) ) {
						$errors[] = $this->msg( 'masseditregex-page-not-exists',
							$target->getPrefixedText() )->escaped();
					}
					$iArticleCount++;
					if ( $iArticleCount >= $pageCountLimit ) {
						$htmlDiff .= Html::element( 'p', [],
							$this->msg( 'masseditregex-max-preview-diffs' )
								->numParams( $pageCountLimit )
								->text()
						);
						break;
					}
				}

			}
		} catch ( MWException $e ) {
			$errors[] = htmlspecialchars( $e );

			// Force a preview if there was a bad regex
			if ( !$isPreview ) {
				$out->addHTML( '</ul>' );
			}
			$isPreview = true;
		}

		if ( !$isPreview ) {
			$out->addHTML( '</ul>' );
		}

		if ( ( $iArticleCount == 0 ) && !$errors ) {
			$errors[] = $this->msg( 'masseditregex-err-nopages' )->escaped();
			// Force a preview if there was nothing to do
			$isPreview = true;
		}

		if ( $errors ) {
			$out->addHTML( '<div class="errorbox">' );
			$out->addHTML( $this->msg( 'masseditregex-editfailed' )->escaped() );

			$out->addHTML( '<ul><li>' );
			$out->addHTML( implode( '</li><li> ', $errors ) );
			$out->addHTML( '</li></ul></div>' );
		}

		if ( $isPreview ) {
			// Show the form again ready for further editing if we're just previewing
			$this->showForm();

			// Show the diffs now (after any errors)
			$out->addHTML( '<hr style="margin: 1em;"/>' );
			$out->addHTML( $htmlDiff );
		} else {
			$out->addWikiMsg( 'masseditregex-num-articles-changed', $iArticleCount );
			$out->addHTML(
				$this->getLinkRenderer()->makeKnownLink(
					SpecialPage::getSafeTitleFor( 'Contributions', $getuser->getName() ),
					$this->msg( 'masseditregex-view-full-summary' )->text()
				)
			);
		}
	}

	/**
	 * Display the special page, and run the regexes if a form is being submitted
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$getuser = $this->getUser();
		$out->addModules( 'MassEditRegex' );

		$this->setHeaders();

		// Check permissions
		if ( !$getuser->isAllowed( 'masseditregex' ) ) {
			$this->displayRestrictionError();
		}

		// Show a message if the database is in read-only mode
		$this->checkReadOnly();

		// If user is blocked, s/he doesn't need to access this page
		if ( $getuser->getBlock() ) {
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			throw new UserBlockedError( $getuser->getBlock() );
		}

		$this->outputHeader();

		$request = $this->getRequest();
		$strPageList = $request->getText( 'wpPageList', 'Sandbox' );
		$this->aPageList = explode( "\n", trim( $strPageList ) );
		$this->strPageListType = $request->getText( 'wpPageListType', 'pagenames' );

		$this->sk = $this->getSkin();

		$this->strMatch = $request->getText( 'wpMatch', '/hello (.*)\n/' );

		$this->strReplace = $request->getText( 'wpReplace', 'goodbye $1' );

		$summary = $request->getText( 'wpSummary', '' );
		$this->isClientSide = $request->getVal( 'wpClientSide', '0' ) === '1';

		$this->massEditRegex = new MassEditRegex(
			$this->strMatch, $this->strReplace, $summary, $getuser
		);

		if ( $request->wasPosted() ) {
			if ( $getuser->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$this->perform( !$request->getCheck( 'wpSave' ) );
			} else {
				$out->addWikiMsg( 'sessionfailure' );
			}
		} else {
			$this->showForm();
			$this->showHints();
		}
	}

	/**
	 * Display the form requesting the regexes from the user.
	 */
	function showForm() {
		$out = $this->getOutput();
		$getuser = $this->getUser();

		$out->addWikiMsg( 'masseditregextext' );
		$titleObj = SpecialPage::getTitleFor( 'MassEditRegex' );

		$out->addHTML(
			Html::openElement( 'form', [
				'id' => 'masseditregex',
				'method' => 'post',
				'action' => $titleObj->getLocalURL( 'action=submit' )
			] ) .
			Html::label( $this->msg( 'masseditregex-pagelisttxt' )->text(), 'wpPageList' ) .
			Html::textarea(
				'wpPageList',
				implode( "\n", $this->aPageList ),
				[ 'id' => 'wpPageList', 'rows' => 5 ]
			) .
			Html::element( 'span', [], $this->msg( 'masseditregex-listtype-intro' )->text() ) .
			Html::openElement( 'ul', [
				'style' => 'list-style: none' // don't want any bullets for radio btns
			] )
		);

		// Generate HTML for the radio buttons (one for each list type)
		foreach ( [ 'pagenames', 'pagename-prefixes', 'categories', 'backlinks' ]
			as $strValue ) {

			$out->addHTML( Html::rawElement( 'li', [],
				// Give grep a chance to find the usages:
				// masseditregex-listtype-pagenames, masseditregex-listtype-pagename-prefixes,
				// masseditregex-listtype-categories, masseditregex-listtype-backlinks
				Html::radio(
					'wpPageListType',
					$strValue == $this->strPageListType,
					[ 'value' => $strValue, 'id' => 'masseditregex-radio-' . $strValue ]
				) . "\u{00A0}" . Html::label(
					$this->msg( 'masseditregex-listtype-' . $strValue )->text(),
					'masseditregex-radio-' . $strValue
				)
			) );
		}
		$out->addHTML(
			Html::closeElement( 'ul' ) .

			// Display the textareas for the regex and replacement to go into

			Html::rawElement(
				'div',
				[ 'style' => 'display: flex; column-gap: 4px;' ],
				Html::rawElement(
					'div',
					[ 'style' => 'flex: 1;' ],
					Html::label( $this->msg( 'masseditregex-matchtxt' )->text(), 'wpMatch' ) .
					Html::textarea( 'wpMatch', $this->strMatch, [ 'id' => 'wpMatch', 'rows' => 5 ] )
				) .
				Html::rawElement(
					'div',
					[ 'style' => 'flex: 1;' ],
					Html::label( $this->msg( 'masseditregex-replacetxt' )->text(), 'wpReplace' ) .
					Html::textarea( 'wpReplace', $this->strReplace, [ 'id' => 'wpReplace', 'rows' => 5 ] )
				)
			) .

			Html::openElement( 'div', [
				'class' => 'editOptions',
				'style' => 'margin: 1ex;'
			] ) .

			// Display the edit summary and preview

			Html::rawElement( 'span',
				[
					'class' => 'mw-summary',
					'id' => 'wpSummaryLabel'
				],
				Html::label( $this->msg( 'summary' )->text(), 'wpSummary' )
			) . ' ' .

			Html::input( 'wpSummary',
				$this->massEditRegex->getSummary(),
				'text',
				[
					'id' => 'wpSummary',
					'size' => 60,
					'maxlength' => '200',
				]
			) .

			Html::rawElement( 'div',
				[ 'class' => 'mw-summary-preview' ],
				$this->msg( 'summary-preview' )->parse() .
					MediaWikiServices::getInstance()->getCommentFormatter()
						->formatBlock( $this->massEditRegex->getSummary() )
			) .
			Html::closeElement( 'div' ) . // class=editOptions

			// Display the preview + execute buttons
			Html::submitButton( $this->msg( 'masseditregex-executebtn' )->text(), [
				'id' => 'wpSave',
				'name' => 'wpSave',
				'accesskey' => $this->msg( 'accesskey-save' )->text(),
				'title' => $this->msg( 'masseditregex-tooltip-execute' )->text() .
					' [' . $this->msg( 'accesskey-save' )->text() . ']',
			] ) .

			Html::submitButton( $this->msg( 'showpreview' )->text(), [
				'id' => 'wpPreview',
				'name' => 'wpPreview',
				'accesskey' => $this->msg( 'accesskey-preview' )->text(),
				'title' => $this->msg( 'tooltip-preview' )->text() .
					' [' . $this->msg( 'accesskey-preview' )->text() . ']',
			] ) .

			Html::hidden( 'wpEditToken', $getuser->getEditToken(), [
				'id' => 'wpEditToken',
			] ) .

			Html::rawElement( 'span',
				[
					'style' => 'margin-left: 1em;'
				],
				Html::check(
					'wpClientSide',
					$this->isClientSide,
					[ 'id' => 'wpClientSide', 'title' => $this->msg( 'masseditregex-js-execution' )->text() ]
				) . "\u{00A0}" . Html::label(
					$this->msg( 'masseditregex-js-clientside' )->text(),
					'wpClientSide',
					[ 'title' => $this->msg( 'masseditregex-js-execution' )->text() ]
				)
			)
		);

		$out->addHTML( Html::closeElement( 'form' ) );
		$out->addModules( 'MassEditRegex' );
	}

	/**
	 * Show a short table of regex examples.
	 */
	function showHints() {
		$out = $this->getOutput();
		$getuser = $this->getUser();

		$out->addHTML(
			Html::element( 'p', [], $this->msg( 'masseditregex-hint-intro' )->text() )
		);
		$out->addHTML( self::buildTable(
			// Table rows (the hints)
			[
				[
					'/$/',
					'abc',
					$this->msg( 'masseditregex-hint-toappend' )->text()
				],
				[
					'/$/',
					'\\n[[Category:New]]',
					// Since we can't pass "rowspan=2" to the hint text above, we'll
					// have to display it again
					$this->msg( 'masseditregex-hint-toappend' )->text()
				],
				[
					'/{{OldTemplate}}/',
					'',
					$this->msg( 'masseditregex-hint-remove' )->text()
				],
				[
					'/\\[\\[Category:[^]]+\]\]/',
					'',
					$this->msg( 'masseditregex-hint-removecat' )->text()
				],
				[
					'/(\\[\\[[^]]*\\|[^]]*)AAA(.*\\]\\])/',
					'$1BBB$2',
					$this->msg( 'masseditregex-hint-renamelink' )->text()
				],
			],

			// Table attributes
			[
				'class' => 'wikitable'
			],

			// Table headings
			[
				$this->msg( 'masseditregex-hint-headmatch' )->text(), // really needs width 12em
				$this->msg( 'masseditregex-hint-headreplace' )->text(), // really needs width 12em
				$this->msg( 'masseditregex-hint-headeffect' )->text()
			]

		) ); // self::buildTable
	}

	/**
	 * @param string[][] $rows
	 * @param array $attribs An array of attributes to apply to the table tag
	 * @param array $headers An array of strings to use as table headers
	 * @return string
	 */
	private static function buildTable( $rows, $attribs, $headers ) {
		$s = Html::openElement( 'table', $attribs );

		$s .= Html::openElement( 'thead', $attribs );

		foreach ( $headers as $header ) {
			$s .= Html::element( 'th', [], $header );
		}
		$s .= Html::closeElement( 'thead' );

		foreach ( $rows as $cells ) {
			$s .= Html::openElement( 'tr' );

			foreach ( $cells as $cell ) {
				$s .= Html::element( 'td', [], $cell );
			}

			$s .= Html::closeElement( 'tr' );
		}

		$s .= Html::closeElement( 'table' );

		return $s;
	}

	/**
	 * @param SkinTemplate $sktemplate
	 * @param array[][] &$links
	 *
	 * @return true
	 */
	public static function efSkinTemplateNavigationUniversal( $sktemplate, &$links ) {
		$title = $sktemplate->getTitle();
		$ns = $title->getNamespace();

		if ( !$sktemplate->getUser()->isAllowed( 'masseditregex' ) ) {
			return true;
		}

		if ( $ns == NS_CATEGORY ) {
			$url = SpecialPage::getTitleFor( 'MassEditRegex' )->getLocalURL(
				[
					'wpPageList' => $title->getText(),
					'wpPageListType' => 'categories',
				]
			);
		} elseif (
			( $ns == NS_SPECIAL )
			&& ( $title->isSpecial( 'Whatlinkshere' ) )
		) {
			$titleParts = MediaWikiServices::getInstance()
				->getSpecialPageFactory()
				->resolveAlias( $title->getText() );

			$url = SpecialPage::getTitleFor( 'MassEditRegex' )->getLocalURL(
				[
					'wpPageList' => $titleParts[1],
					'wpPageListType' => 'backlinks',
				]
			);
		} else {
			// No tab
			return true;
		}

		$links['views']['masseditregex'] = [
			'class' => false,
			'text' => wfMessage( 'masseditregex-editall' )->text(),
			'href' => $url,
			'context' => 'main',
		];
		return true;
	}

	/**
	 * Call MassEditRegex::editPage() or MassEditRegex::previewPage()
	 * @param $title
	 * @param $isPreview
	 * @param $htmlDiff
	 * @deprecated this is just a wrapper function for legacy code, do not use.
	 *   Instead use editPage or previewPage in MassEditRegex
	 * @return bool
	 */
	private function editPage( $title, $isPreview, &$htmlDiff ) {
		$out = $this->getOutput();
		$getuser = $this->getUser();
		try {
			if ( $isPreview ) {
				$htmlDiff .= $this->massEditRegex->previewPage( $title );
			} else {
				$changeCount = $this->massEditRegex->editPage( $title );
				$out->addHTML( '<li>' . $this->msg( 'masseditregex-num-changes',
					$title->getPrefixedText(), $changeCount )->escaped() . '</li>' );
			}
			return true;
		} catch ( Exception $e ) {
			wfDebug( $e->getMessage() );
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'pagetools';
	}
}
