<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();
/**
 * Allow users in the Bot group to edit many articles in one go by applying
 * regular expressions to a list of pages.
 *
 * @file
 * @ingroup Extensions
 *
 * @link http://www.mediawiki.org/wiki/Extension:MassEditRegex Documentation
 *
 * @author Adam Nielsen <malvineous@shikadi.net>
 * @author Kim Eik <kim@heldig.org>
 * @copyright Copyright © 2009-2015 Adam Nielsen
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'Mass Edit via Regular Expressions',
	'version' => '8.0.0',
	'author' => 'Adam Nielsen',
	'url' => 'https://www.mediawiki.org/wiki/Extension:MassEditRegex',
	'descriptionmsg' => 'masseditregex-desc'
);

$dir = dirname(__FILE__) . '/';
$wgMessagesDirs['MassEditRegex'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['MassEditRegex'] = $dir . 'MassEditRegex.i18n.php';
$wgExtensionMessagesFiles['MassEditRegexAlias'] = $dir . 'MassEditRegex.alias.php';
$wgAutoloadClasses['MassEditRegex'] = $dir . 'MassEditRegex.class.php';
$wgAutoloadClasses['MassEditRegexSpecialPage'] = $dir . 'MassEditRegex.special.php';
$wgAutoloadClasses['MassEditRegexAPI'] = $dir . 'MassEditRegex.api.php';
$wgSpecialPages['MassEditRegex'] = 'MassEditRegexSpecialPage';
$wgSpecialPageGroups['MassEditRegex'] = 'pagetools';

// Required permission to use Special:MassEditRegex
$wgAvailableRights[] = 'masseditregex';

$wgHooks['SkinTemplateNavigation::Universal'][] = 'MassEditRegexSpecialPage::efSkinTemplateNavigationUniversal';
$wgHooks['BaseTemplateToolbox'][] = 'MassEditRegexSpecialPage::efBaseTemplateToolbox';

$wgResourceModules['MassEditRegex'] = array(
	'scripts' => array(
		'MassEditRegex.js'
	),
	'dependencies' => array(
		'jquery.ui.dialog'
	),
	'group' => 'MassEditRegex',
	'localBasePath' => dirname(__FILE__),
	'remoteExtPath' => 'MassEditRegex',
	'messages' => array (
		'masseditregex-js-execution',
		'masseditregex-js-jobdone',
		'masseditregex-js-editpage',
		'masseditregex-js-working',
		'masseditregex-js-pagenotexist',
		'masseditregex-js-mwapi-api-error',
		'masseditregex-js-mwapi-general-error',
		'masseditregex-js-mwapi-unknown-error',
	)
);

// AJAX
$wgAjaxExportList[] = 'MassEditRegexAPI::edit';
