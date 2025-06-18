<?php

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

class MassEditRegex {
	/**
	 * @var array
	 */
	private $search;

	/**
	 * @var array
	 */
	private $replace;

	/**
	 * @var string
	 */
	private $summary;

	/**
	 * @var \DifferenceEngine
	 */
	private $diffEngine;

	/**
	 * @var \User
	 */
	private $user;

	/**
	 * @param string $search newline delimited string of search regexes
	 * @param string $replace newline delimited string of replacements
	 * @param string $summary
	 * @param User|null $user
	 */
	function __construct( $search, $replace, $summary, \User $user = null ) {
		$this->setReplace( $replace );
		$this->setSearch( $search );
		$this->setSummary( $summary );
		$this->setUser( $user );

		$this->diffEngine = new DifferenceEngine();
	}

	/**
	 * Apply the list of regex expressions to a single page
	 *
	 * @param Title $title
	 *   Page to alter
	 *
	 * @return int number of changes performed on given title
	 */
	public function editPage( \Title $title ) {
		$rev = $this->getRevisionRecord( $title );
		$content = $this->getContent( $rev );
		$curText = $content->getNativeData();
		[ $newText, $changes ] = $this->replaceText( $curText );

		if ( strcmp( $curText, $newText ) != 0 ) {
			MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title )->doUserEditContent(
				ContentHandler::makeContent( $newText, $title ),
				$this->user,
				$this->summary,
				EDIT_UPDATE | EDIT_FORCE_BOT | EDIT_DEFER_UPDATES,
				$rev->getId()
			);
		}

		return $changes;
	}

	/**
	 * Apply the list of regex expressions to a single page for preview
	 *
	 * @param Title $title
	 *   Page to preview
	 *
	 * @return string html diff
	 */
	public function previewPage( \Title $title ) {
		$rev = $this->getRevisionRecord( $title );
		$content = $this->getContent( $rev );
		$curText = $content->getNativeData();
		[ $newText ] = $this->replaceText( $curText );

		$this->diffEngine->setContent( $content, ContentHandler::makeContent( $newText, $title ) );

		return $this->diffEngine->getDiff(
			Html::element(
				'b',
				[],
				$title->getPrefixedText() . ' - ' . wfMessage( 'masseditregex-before' )->text()
			),
			Html::element(
				'b',
				[],
				wfMessage( 'masseditregex-after' )->text()
			)
		);
	}

	/**
	 * Main regex transform function.
	 *
	 * @param string $input
	 * @return array
	 * @throws MWException
	 */
	private function replaceText( $input ) {
		$changes = $iCount = 0;
		foreach ( $this->search as $i => $strMatch ) {
			$strNextReplace = $this->replace[ $i ];
			$result = @preg_replace_callback( $strMatch,
				static function ( $aMatches ) use ( $strNextReplace ) {
					$aFind = [];
					$aReplace = [];
					foreach ( $aMatches as $i => $strMatch ) {
						$aFind[ ] = '$' . $i;
						$aReplace[ ] = $strMatch;
					}
					return str_replace( $aFind, $aReplace, $strNextReplace );
				}, $input, -1, $iCount );
			$changes += $iCount;
			if ( $result !== null ) {
				$input = $result;
			} else {
				throw new MWException( wfMessage( 'masseditregex-badregex' )->text()
					. ' <b>' . htmlspecialchars( $strMatch ) . '</b>' );
			}
		}
		return [ $input, $changes ];
	}

	/**
	 * @param Title $title
	 * @return RevisionRecord
	 * @throws BadTitleError
	 */
	private function getRevisionRecord( \Title $title ) {
		$revisionRecord = MediaWikiServices::getInstance()
			->getRevisionLookup()
			->getRevisionByTitle( $title, 0, IDBAccessObject::READ_LATEST );
		if ( !$revisionRecord ) {
			throw new \BadTitleError( wfMessage( 'masseditregex-norevisions' ) );
		}
		return $revisionRecord;
	}

	/**
	 * @param RevisionRecord $rev
	 * @return Content
	 * @throws PermissionsError
	 */
	private function getContent( $rev ) {
		$content = $rev->getContent( SlotRecord::MAIN, RevisionRecord::FOR_THIS_USER, $this->user );
		if ( !$content ) {
			throw new \PermissionsError( wfMessage( 'masseditregex-noaccess' )->text() );
		}
		return $content;
	}

	/**
	 * @return array
	 */
	public function getReplace() {
		return $this->replace;
	}

	/**
	 * @return array
	 */
	public function getSearch() {
		return $this->search;
	}

	/**
	 * @return string
	 */
	public function getSummary() {
		return $this->summary;
	}

	/**
	 * @return \User
	 */
	public function getUser() {
		return $this->user;
	}

	/**
	 * @param \DifferenceEngine $diffEngine
	 */
	public function setDiffEngine( $diffEngine ) {
		$this->diffEngine = $diffEngine;
	}

	/**
	 * @return \DifferenceEngine
	 */
	public function getDiffEngine() {
		return $this->diffEngine;
	}

	/**
	 * @param \User|null $user
	 */
	public function setUser( $user = null ) {
		if ( $user === null ) {
			$user = User::newFromSession();
		}
		$this->user = $user;
	}

	/**
	 * @param string $replace
	 */
	public function setReplace( $replace ) {
		$replace = explode( "\n", $replace );

		foreach ( $replace as &$str ) {
			// Convert \n into a newline, \\n into \n, \\\n into \<newline>, etc.
			$str = preg_replace(
				[
					'/(^|[^\\\\])((\\\\)*)(\2)\\\\n/',
					'/(^|[^\\\\])((\\\\)*)(\2)n/'
				], [
				"\\1\\2\n",
				"\\1\\2n"
				], $str );
		}

		$this->replace = $replace;
	}

	/**
	 * @param string $search
	 */
	public function setSearch( $search ) {
		$this->search = explode( "\n", trim( $search ) );
	}

	/**
	 * @param string $summary
	 */
	public function setSummary( $summary ) {
		$this->summary = $summary;
	}
}
