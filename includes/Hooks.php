<?php

namespace MediaWiki\Extension\MassEditRegex;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\SpecialPageFactory;
use SkinTemplate;

class Hooks implements
	\MediaWiki\Hook\SkinTemplateNavigation__UniversalHook
{
	public function __construct(
		private readonly SpecialPageFactory $specialPageFactory,
	) {
	}

	/**
	 * @param SkinTemplate $sktemplate
	 * @param array[][] &$links
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$title = $sktemplate->getTitle();
		$ns = $title->getNamespace();

		if ( !$sktemplate->getUser()->isAllowed( 'masseditregex' ) ) {
			return;
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
			$titleParts = $this->specialPageFactory
				->resolveAlias( $title->getText() );

			$url = SpecialPage::getTitleFor( 'MassEditRegex' )->getLocalURL(
				[
					'wpPageList' => $titleParts[1],
					'wpPageListType' => 'backlinks',
				]
			);
		} else {
			// No tab
			return;
		}

		$links['views']['masseditregex'] = [
			'class' => false,
			'text' => wfMessage( 'masseditregex-editall' )->text(),
			'href' => $url,
			'context' => 'main',
		];
	}

}
