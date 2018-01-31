<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();
/**
 * Allow users in the Bot group to edit many articles in one go by applying
 * regular expressions to a list of pages.
 *
 * @file
 * @ingroup SpecialPage
 *
 * @link http://www.mediawiki.org/wiki/Extension:MassEditRegex Documentation
 *
 * @author Adam Nielsen <malvineous@shikadi.net>
 * @copyright Copyright © 2009,2013 Adam Nielsen
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

/// Maximum number of pages/diffs to display when previewing the changes
define('MER_MAX_PREVIEW_DIFFS', 20);

/// Maximum number of pages to edit.
define('MER_MAX_EXECUTE_PAGES', 1000);

/** Main class that define a new special page*/
class MassEditRegexSpecialPage extends SpecialPage {
	private $aPageList;       ///< Array of string - user-supplied page titles
	private $strPageListType; ///< Type of titles (categories, backlinks, etc.)
	private $strMatch;        ///< Match regex from form
	private $strReplace;      ///< Substitution regex from form
	private $isClientSide;    ///< Is the client-side checkbox ticked?
	private $sk;              ///< Skin instance

	/**
	 * @var \MassEditRegex
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
		global $wgOut, $wgUser, $wgLang;

		$pageCountLimit = $isPreview ? MER_MAX_PREVIEW_DIFFS : MER_MAX_EXECUTE_PAGES;
		$errors = array();

		$htmlDiff = '';

		if ( $isPreview ) {
			$this->massEditRegex->getDiffEngine()->showDiffStyle();
		} else {
			$wgOut->addHTML( '<ul>' );
		}

		$iArticleCount = 0;
		try {
			foreach ( $this->aPageList as $pageTitle ) {
				$titleArray = array();
				switch ($this->strPageListType) {
					case 'pagenames': // Can do this in one hit
						$t = Title::newFromText( $pageTitle );
						if ( !$t || !$this->editPage( $t, $isPreview, $htmlDiff ) ) {
							$errors[] = $this->msg( 'masseditregex-page-not-exists',
								$pageTitle )->escaped();
						}
						$iArticleCount++;
						break;

					case 'pagename-prefixes':
						$titles = PrefixSearch::titleSearch( $pageTitle,
							$pageCountLimit - $iArticleCount );
						if ( empty( $titles ) ) {
							$errors[] = $this->msg( 'masseditregex-exprnomatch',
								$pageTitle )->escaped();
							$iArticleCount++;
							continue;
						}

						foreach ( $titles as $title ) {
							$t = Title::newFromText( $title );
							if ( !$t ) {
								$errors[] = wfMessage(  'masseditregex-page-not-exists', $title  )->text();
							} else {
								$titleArray[] = $t;
							}
						}
						break;

					case 'categories':
						$cat = Category::newFromName($pageTitle);
						if ( $cat === false ) {
							$errors[] = $this->msg( 'masseditregex-page-not-exists',
								$pageTitle )->escaped();
							break;
						}
						$titleArray = $cat->getMembers($pageCountLimit - $iArticleCount);
						break;

					case 'backlinks':
						$t = Title::newFromText($pageTitle);
						if ( !$t ) {
							if ( $isPreview ) {
								$errors[] = $this->msg( 'masseditregex-page-not-exists',
									$pageTitle )->escaped();
							}
							continue;
						}
						$blc = $t->getBacklinkCache();
						if ( $t->getNamespace() == NS_TEMPLATE ) {
							// Backlinks for Template pages are in a different table
							$table = 'templatelinks';
						} else {
							$table = 'pagelinks';
						}
						$titleArray = $blc->getLinks($table, false, false,
							$pageCountLimit - $iArticleCount);
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
						$htmlDiff .= Xml::element('p', null,
							$this->msg( 'masseditregex-max-preview-diffs' )
								->numParams( $pageCountLimit )
								->text()
						);
						break;
					}
				}

			}
		} catch (UsageException $e) {
			$errors[] = $e;

			// Force a preview if there was a bad regex
			if ( !$isPreview ) {
				$wgOut->addHTML( '</ul>' );
			}
			$isPreview = true;
		}

		if ( !$isPreview ) {
			$wgOut->addHTML( '</ul>' );
		}

		if ( ( $iArticleCount == 0 ) && empty( $errors ) ) {
			$errors[] = wfMessage(  'masseditregex-err-nopages'  )->text();
			// Force a preview if there was nothing to do
			$isPreview = true;
		}

		if ( !empty($errors ) ) {
			$wgOut->addHTML( '<div class="errorbox">' );
			$wgOut->addHTML( wfMessage(  'masseditregex-editfailed'  )->text() );

			$wgOut->addHTML( '<ul><li>' );
			$wgOut->addHTML( join( '</li><li> ', $errors) );
			$wgOut->addHTML( '</li></ul></div>' );
		}

		if ( $isPreview ) {
			// Show the form again ready for further editing if we're just previewing
			$this->showForm();

			// Show the diffs now (after any errors)
			$wgOut->addHTML( '<hr style="margin: 1em;"/>' );
			$wgOut->addHTML( $htmlDiff );
		} else {
			$wgOut->addWikiMsg( 'masseditregex-num-articles-changed', $iArticleCount );
			$wgOut->addHTML(
				Linker::link(
					SpecialPage::getSafeTitleFor( 'Contributions', $wgUser->getName() ),
					$this->msg( 'masseditregex-view-full-summary' )->escaped(),
					array(),
					array(),
					array( 'known' )
				)
			);
		}
	}

	/// Display the special page, and run the regexes if a form is being submitted
	public function execute( $par ) {
		global $wgUser;

		$wgOut = $this->getOutput();
		$wgOut->addModules('MassEditRegex');

		$this->setHeaders();

		// Check permissions
		if ( !$wgUser->isAllowed( 'masseditregex' ) ) {
			$this->displayRestrictionError();
			return;
		}

		// Show a message if the database is in read-only mode
		if ( wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		// If user is blocked, s/he doesn't need to access this page
		if ( $wgUser->isBlocked() ) {
			throw new UserBlockedError( $wgUser->getBlock() );
		}

		$this->outputHeader();

		$wgRequest = $this->getRequest();
		$strPageList = $wgRequest->getText( 'wpPageList', 'Sandbox' );
		$this->aPageList = explode( "\n", trim( $strPageList ) );
		$this->strPageListType = $wgRequest->getText( 'wpPageListType', 'pagenames' );

		$this->sk = $this->getSkin();

		$this->strMatch = $wgRequest->getText( 'wpMatch', '/hello (.*)\n/' );

		$this->strReplace = $wgRequest->getText( 'wpReplace', 'goodbye $1' );

		$summary = $wgRequest->getText( 'wpSummary', '' );
		$this->isClientSide = $wgRequest->getVal( 'wpClientSide', false ) == 1;

		$this->massEditRegex = new MassEditRegex(
			$this->strMatch, $this->strReplace, $summary, $wgUser
		);

		if ( $wgRequest->wasPosted() ) {
			$this->perform( !$wgRequest->getCheck('wpSave') );
		} else {
			$this->showForm();
			$this->showHints();
		}

	}

	/// Display the form requesting the regexes from the user.
	function showForm() {
		$wgOut = $this->getOutput();

		$wgOut->addWikiMsg( 'masseditregextext' );
		$titleObj = SpecialPage::getTitleFor( 'MassEditRegex' );

		$wgOut->addHTML(
			Xml::openElement('form', array(
				'id' => 'masseditregex',
				'method' => 'post',
				'action' => $titleObj->getLocalURL('action=submit')
			)) .
			Xml::element('p',
				null, wfMessage(  'masseditregex-pagelisttxt'  )->text()
			) .
			Xml::textarea(
				'wpPageList',
				join( "\n", $this->aPageList )
			) .
			Xml::element('span',
				null, wfMessage(  'masseditregex-listtype-intro'  )->text()
			) .
			Xml::openElement('ul', array(
				'style' => 'list-style: none' // don't want any bullets for radio btns
			))
		);


		// Generate HTML for the radio buttons (one for each list type)
		foreach (array('pagenames', 'pagename-prefixes', 'categories', 'backlinks')
			as $strValue){

			// Have to use openElement because putting an Xml::xxx return value
			// inside an Xml::element causes the HTML code to be escaped and appear
			// on the page.
			$wgOut->addHTML(
				Xml::openElement('li') .
				// Give grep a chance to find the usages:
				// masseditregex-listtype-pagenames, masseditregex-listtype-pagename-prefixes,
				// masseditregex-listtype-categories, masseditregex-listtype-backlinks
				Xml::radioLabel(
					wfMessage(  'masseditregex-listtype-' . $strValue  )->text(),
					'wpPageListType',
					$strValue,
					'masseditregex-radio-' . $strValue,
					$strValue == $this->strPageListType
				) .
				Xml::closeElement('li')
			);
		}
		$wgOut->addHTML(
			Xml::closeElement('ul') .

			// Display the textareas for the regex and replacement to go into

			// Can't use Xml::buildTable because we need to put code into the table
			Xml::openElement('table', array(
				'style' => 'width: 100%'
			)) .
				Xml::openElement('tr') .
					Xml::openElement('td') .
						Xml::element('p', null, wfMessage(  'masseditregex-matchtxt'  )->text()) .
						Xml::textarea(
							'wpMatch',
							$this->strMatch  // use original value
						) .
						Xml::closeElement('textarea') .
					Xml::closeElement('td') .
					Xml::openElement('td') .
						Xml::element('p', null, wfMessage(  'masseditregex-replacetxt'  )->text()) .
						Xml::textarea(
							'wpReplace',
							$this->strReplace  // use original value
						) .
						Xml::closeElement('textarea') .
					Xml::closeElement('td') .
					Xml::closeElement('tr') .
			Xml::closeElement('table') .

			Xml::openElement( 'div', array(
				'class' => 'editOptions',
				'style' => 'margin: 1ex;'
			) ) .

			// Display the edit summary and preview

			Xml::tags( 'span',
				array(
					'class' => 'mw-summary',
					'id' => 'wpSummaryLabel'
				),
				Xml::tags( 'label', array(
					'for' => 'wpSummary'
				), wfMessage(  'summary'  )->text() )
			) . ' ' .

			Xml::input( 'wpSummary',
				60,
				$this->massEditRegex->getSummary(),
				array(
					'id' => 'wpSummary',
					'maxlength' => '200',
					'tabindex' => '1'
				)
			) .

			Xml::tags( 'div',
				array( 'class' => 'mw-summary-preview' ),
				$this->msg( 'summary-preview' )->parse() .
					Linker::commentBlock( $this->massEditRegex->getSummary() )
			) .
			Xml::closeElement( 'div' ) . // class=editOptions

			// Display the preview + execute buttons
			Xml::element('input', array(
				'id'        => 'wpSave',
				'name'      => 'wpSave',
				'type'      => 'submit',
				'value'     => wfMessage(  'masseditregex-executebtn'  )->text(),
				'accesskey' => wfMessage(  'accesskey-save'  )->text(),
				'title'     => wfMessage(  'masseditregex-tooltip-execute'  )->text().' ['.wfMessage(  'accesskey-save'  )->text().']',
			)) .

			Xml::element('input', array(
				'id'        => 'wpPreview',
				'name'      => 'wpPreview',
				'type'      => 'submit',
				'value'     => wfMessage( 'showpreview' )->text(),
				'accesskey' => wfMessage( 'accesskey-preview' )->text(),
				'title'     => wfMessage(  'tooltip-preview'  )->text().' ['.wfMessage(  'accesskey-preview'  )->text().']',
			)) .

			Xml::tags( 'span',
				array(
					'style' => 'margin-left: 1em;'
				),
				Xml::checkLabel(
					wfMessage('masseditregex-js-clientside'),
					'wpClientSide',
					'wpClientSide',
					$this->isClientSide,
					array(
						'title' => wfMessage('masseditregex-js-execution'),
					)
				)
			)
		);

		$wgOut->addHTML( Xml::closeElement('form') );
		$wgOut->addModules('MassEditRegex');
	}

	/// Show a short table of regex examples.
	function showHints() {
		global $wgOut;

		$wgOut->addHTML(
			Xml::element( 'p', null, wfMessage(  'masseditregex-hint-intro'  )->text() )
		);
		$wgOut->addHTML(Xml::buildTable(

			// Table rows (the hints)
			array(
				array(
					'/$/',
					'abc',
					wfMessage(  'masseditregex-hint-toappend'  )->text()
				),
				array(
					'/$/',
					'\\n[[Category:New]]',
					// Since we can't pass "rowspan=2" to the hint text above, we'll
					// have to display it again
					wfMessage(  'masseditregex-hint-toappend'  )->text()
				),
				array(
					'/{{OldTemplate}}/',
					'',
					wfMessage(  'masseditregex-hint-remove'  )->text()
				),
				array(
					'/\\[\\[Category:[^]]+\]\]/',
					'',
					wfMessage(  'masseditregex-hint-removecat'  )->text()
				),
				array(
					'/(\\[\\[[^]]*\\|[^]]*)AAA(.*\\]\\])/',
					'$1BBB$2',
					wfMessage(  'masseditregex-hint-renamelink'  )->text()
				),
			),

			// Table attributes
			array(
				'class' => 'wikitable'
			),

			// Table headings
			array(
				wfMessage(  'masseditregex-hint-headmatch'  )->text(), // really needs width 12em
				wfMessage(  'masseditregex-hint-headreplace'  )->text(), // really needs width 12em
				wfMessage(  'masseditregex-hint-headeffect'  )->text()
			)

		)); // Xml::buildTable

	}

	public static function efSkinTemplateNavigationUniversal( &$sktemplate, &$links )
	{
		$title = $sktemplate->getTitle();
		$ns = $title->getNamespace();

		if ( !$sktemplate->getUser()->isAllowed('masseditregex') ) {
			return true;
		}

		if ( $ns == NS_CATEGORY ) {
			$url = SpecialPage::getTitleFor( 'MassEditRegex' )->getLocalURL(
				array(
					'wpPageList' => $title->getText(),
					'wpPageListType' => 'categories',
				)
			);
		} elseif (
			( $ns == NS_SPECIAL )
			&& ( $title->isSpecial( 'Whatlinkshere' ) )
		) {
			$titleParts = SpecialPageFactory::resolveAlias($title->getText());

			$url = SpecialPage::getTitleFor( 'MassEditRegex' )->getLocalURL(
				array(
					'wpPageList' => $titleParts[1],
					'wpPageListType' => 'backlinks',
				)
			);
		} else {
			// No tab
			return true;
		}

		$links['views']['masseditregex'] = array(
			'class' => false,
			'text' => wfMessage('masseditregex-editall')->text(),
			'href' => $url,
			'context' => 'main',
		);
		return true;
	}

	public static function efBaseTemplateToolbox( &$tpl, &$toolbox ) {
		global $wgTitle;
		if ( !$wgTitle->isSpecial( 'MassEditRegex' ) ) return true;

		// Hide the 'printable version' link as the shortcut key conflicts with
		// the preview button.
		unset($toolbox['print']);
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
		global $wgOut;
		try {
			if ( $isPreview ) {
				$htmlDiff .= $this->massEditRegex->previewPage( $title );
			} else {
				$changeCount = $this->massEditRegex->editPage( $title );
				$wgOut->addHTML( '<li>' . $this->msg( 'masseditregex-num-changes',
					$title->getPrefixedText(), $changeCount )->escaped() . '</li>' );
			}
			return true;
		} catch ( Exception $e ) {
			wfDebug( $e->getMessage() );
			return false;
		}
	}

	protected function getGroupName() {
		return 'pagetools';
	}
}
