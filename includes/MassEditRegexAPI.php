<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class MassEditRegexAPI {
	/**
	 * @param int $pageid
	 * @param string $search
	 * @param string $replace
	 * @param string $summary
	 *
	 * @return string|false
	 */
	public static function edit( $pageid, $search, $replace, $summary ) {
		$massEditRegex = new MassEditRegex( $search, $replace, $summary );

		$user = User::newFromSession();

		// Check permissions
		if ( !$user->isAllowed( 'masseditregex' ) ) {
			return json_encode( [
				'error' => wfMessage( 'masseditregex-noaccess' )->text()
			] );
		}

		// Show a message if the database is in read-only mode
		if ( MediaWikiServices::getInstance()->getReadOnlyMode()->isReadOnly() ) {
			return json_encode( [
				'error' => wfMessage( 'masseditregex-readonlydb' )->text()
			] );
		}

		// If user is blocked, s/he doesn't need to access this page
		if ( $user->getBlock() ) {
			return json_encode( [
				'error' => wfMessage( 'masseditregex-blocked' )->text()
			] );
		}

		return json_encode( [
			'changes' => $massEditRegex->editPage( Title::newFromID( $pageid ) )
		] );
	}
}
