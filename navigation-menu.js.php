<?php

header('Content-Type: application/javascript');

require_once('common.inc.php');

$cache->pushKey(basename(__FILE__, '.php'));

if (isset($_REQUEST['user_id'])) {
	if (is_numeric($_REQUEST['user_id'])) {
		$userId = $_REQUEST['user_id'];
	} else {
		$userId = preg_replace('|.*users/(\d+)/?.*|', '$1', $_REQUEST['user_id']);
	}
}

if (empty($userId)) {
	exit;
}

/* get the current user's preferences */
$response = $customPrefs->query("
	SELECT * FROM `users`
		WHERE
			`id` = '" . $userId . "'
");
$userPrefs = $response->fetch_assoc();
// FIXME groups are now stored in separate tables
//$userPrefs['groups'] = unserialize($userPrefs['groups']);

/* build our list of courses that should be removed from the Courses menu */
$response = $customPrefs->query("
	SELECT * FROM `courses`
		WHERE
			`menu-visible` = '0'
");
while ($c = $response->fetch_assoc()) {
	$coursesToHide[] = $c['id'];
}

function isMenu($menuItem) {
	return !is_numeric($menuItem['menu']);
}

function isColumn($menuItem) {
	return !is_numeric($menuItem['column']) && !isMenu($menuItem);
}

function isSection($menuItem) {
	return !is_numeric($menuItem['section']) && !isColumn($menuItem);
}

function isItem($menuItem) {
	return is_numeric($menuItem['section']);
}

function nonempty($flag, $value) {
	return (strlen($flag) ? $value : '');
}

function startMenu($menuItem, $columns = 1) {
	global $userPrefs, $pluginMetadata;
	
	return '<li><a' . nonempty($menuItem['target'], " target=\"{$menuItem['target']}\"") . ' href="' . (empty($menuItem['url']) ? '#' : $menuItem['url']) . '" class="menubar-item' . (empty($menuItem['url']) ? ' noclick' : '') . '">' . $menuItem['title'] . '</a><ul>';
}

function endMenu() {
	return '</ul></li>';
}

function startColumn($menuItem) {
	return '';
}

function endColumn() {
	return '';
}

function startSection($menuItem) {
	return nonempty($menuItem['title'], "<li><a href=\"#\" class=\"section-title noclick\"><strong>{$menuItem['title']}</strong></a></li>");
}

function endSection() {
	return '';
}

function menuItem($menuItem) {
	global $userPrefs, $pluginMetadata;
	return '<li><a' . nonempty($menuItem['target'], " target=\"{$menuItem['target']}\"") . " href=\"{$menuItem['url']}\">{$menuItem['title']}</a></li>";
}

$menuHtml = $cache->getCache($userPrefs['id']);
if (!$menuHtml) {
	/* build a set of regexp conditions to find this user's groups in serialized group lists hanging off of menu items */
	$userGroups = 'FALSE';
	// FIXME reimpliment groups
	/*if (is_array($userPrefs['groups'])) {
		$userGroups == '';
		foreach($userPrefs['groups'] as $g) {
			$userGroups = (strlen($userGroups) ? " {$userGroups} OR " : '') . "`groups` REGEXP 'a:[0-9]+\{(i:[0-9]+;i:[0-9]+;)*i:[0-9]+;i:{$g};(i:[0-9]+;i:[0-9]+;)*\}'";
		}
	}*/
	
	/* build the menus */
	// TODO no doubt this could be elegantly wrapped up in a recursive function full of menu-related puns
	$menuHtml = array();
	$menus = $customPrefs->query("
		SELECT *
			FROM `menu-items`
			WHERE
				`menu` IS NULL AND
				(
					`role` IS NULL OR
					`role` = '" . $userPrefs['role'] . "'
				) AND (
					`groups` IS NULL OR
					" . $userGroups . "
				)
			ORDER BY
				`order` ASC,
				`title` ASC
	");
	for ($i = 0; $m = $menus->fetch_assoc(); $i++) {
		$columns = $customPrefs->query("
			SELECT *
				FROM `menu-items`
				WHERE
					`menu` = '" . $m['id'] . "' AND
					`column` IS NULL AND
					(
						`role` IS NULL OR
						`role` = '" . $userPrefs['role'] . "'
					) AND (
						`groups` IS NULL OR
						" . $userGroups . "
					)
				ORDER BY
					`order` ASC,
					`title` ASC
		");
		$menuHtml[$i] = startMenu($m, $columns->num_rows);
		while ($c = $columns->fetch_assoc()) {
			$menuHtml[$i] .= startColumn($c);
			$sections = $customPrefs->query("
				SELECT *
					FROM `menu-items`
					WHERE
						`menu` = '" . $m['id'] . "' AND
						`column` = '" . $c['id'] . "' AND
						`section` IS NULL AND
						(
							`role` IS NULL OR
							`role` = '" . $userPrefs['role'] . "'
						) AND (
							`groups` IS NULL OR
							" . $userGroups . "
						)
					ORDER BY
						`order` ASC,
						`title` ASC
			");
			while ($s = $sections->fetch_assoc()) {
				$menuHtml[$i] .= startSection($s);
				$items = $customPrefs->query("
					SELECT *
						FROM `menu-items`
						WHERE
							`menu` = '" . $m['id'] . "' AND
							`column` = '" . $c['id'] . "' AND
							`section` = '" . $s['id'] . "' AND
							(
								`role` IS NULL OR
								`role` = '" . $userPrefs['role'] . "'
							) AND (
								`groups` IS NULL OR
								" . $userGroups . "
							)
						ORDER BY
							`order` ASC,
							`title` ASC
				");
				while ($item = $items->fetch_assoc()) {
					$menuHtml[$i] .= menuItem($item);
				}
				$menuHtml[$i] .= endSection();
			}
			$menuHtml[$i] .= endColumn();
		}
		//if ($columns->num_rows > 0) {
			$menuHtml[$i] .= endMenu();
		//}
	}
	$cache->setCache($userPrefs['id'], $menuHtml);
}

$menuHtml = str_replace('@@LOCATION@@', $_SERVER['HTTP_REFERER'], $menuHtml);

?>
var global_navigation_menu = {
	// types of user
	// FIXME pull this from DB too
	USER_CLASS_STUDENT: 'student',
	USER_CLASS_STAFF: 'staff',
	USER_CLASS_FACULTY: 'faculty',
	USER_CLASS_NO_MENU: 'no-menu',
	
	// the class of the current user
	userClass: <?= (strlen($userPrefs['role']) ? "'{$userPrefs['role']}'" : 'USER_CLASS_NO_MENU') ?>,
			
	appendMenus: function() {
		// add the custom menu to the menubar
		// if you wanted to add more menus, define another menu structure like resources and call appendMenu() with it as a parameter (menus would be added in the order that the appendMenu() calls occur)
		if (this.userClass != this.USER_CLASS_NO_MENU) {
			// append menus
			$('#menu').after('<ul id="canvashack-global-navigation-menu"></ul>');
<?php
			foreach ($menuHtml as $html) {
				echo "$('#canvashack-global-navigation-menu').append('$html');";
			} ?>
			$('#canvashack-global-navigation-menu').menu({position: {at: 'left bottom'}});
			$('#canvashack-global-navigation-menu').css('top', $('#menu').children().last().position().top);
			$('#canvashack-global-navigation-menu').css('left', $('#menu').children().last().position().left + $('#menu').children().last().width());
			$('#canvashack-global-navigation-menu .noclick').click(function(e) { e.preventDefault(); });
		}
	}
};

global_navigation_menu.appendMenus();