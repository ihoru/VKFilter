<?

header('Content-type: text/html; charset=utf8');
/*
 * Список поддерживаемых ссылок:
 * * Пост на стене:
 * ** https://vk.com/wall-21090314_139626
 * ** https://vk.com/iwantyou?w=wall-43776215_2360761
 * * Альбом с фотографиями (достаются авторы фотографий):
 * ** https://vk.com/album-43776215_208738214
 * ** https://vk.com/album-43776215_208738214?rev=1
 * ** https://vk.com/album-43776215_208738214?rev=1
 * ** https://vk.com/iwantyou?z=album-43776215_208738214
*/
$filter_default = array(
	'city' => 1, // Москва
	'sex' => 1, // девушки
	'age_min' => 18,
	'age_max' => 30,
	'age_require' => false,
	'online' => false,
	'relations' => array(),
	'height' => 300,
);
$url = isset($_REQUEST['url']) ? strval($_REQUEST['url']) : '';
$debug = isset($_REQUEST['debug']);
$reset = isset($_REQUEST['reset']);
$filter = isset($_REQUEST['filter']) && !$reset ? (array)$_REQUEST['filter'] : array();
if (!$reset) {
	if (isset($_COOKIE['filter']) && !$filter) {
		$filter = (array)json_decode($_COOKIE['filter']);
	}
	$filter = array_merge($filter_default, $filter);
}
foreach ($filter_default as $k => $v) {
	if (is_numeric($v)) {
		$filter[$k] = isset($filter[$k]) ? intval($filter[$k]) : 0;
	} elseif (is_bool($v)) {
		$filter[$k] = isset($filter[$k]) ? (bool)($filter[$k]) : false;
	} elseif (is_array($v)) {
		$filter[$k] = isset($filter[$k]) ? (array)($filter[$k]) : array();
	}
}
$filter['sex'] = max(0, min(2, $filter['sex']));
$filter['height'] = max(min($filter['height'], 2000), 100);
setcookie('filter', json_encode($filter), strtotime('+1 month'));
$filter = htmlspecialchars_recursive($filter);
$exclude_users = isset($_COOKIE['exclude_users']) ? array_unique(array_filter(array_map('intval', explode(',', $_COOKIE['exclude_users'])))) : array();
$cities = array(
	'0' => 'любой',
	'-1' => 'не указано',
	'1' => 'Москва',
	'72' => 'Краснодар',
	'106' => 'Оренбург',
);
$relations = array(
	0 => 'не указано',
	1 => 'свободен(а)',
	6 => 'в активном поиске',
	5 => 'всё сложно',
	2 => 'есть пара',
	7 => 'влюблён(а)',
	3 => 'помолвлен(а)',
	4 => 'в браке',
);
$heights = array(
	'100' => 'маленькие (100)',
	'300' => 'средние (300)',
	'600' => 'большие (600)',
	'1000' => 'огромные (1000)',
);
$zoom_max_height = 900;
$zoom_max_width = 900;

$base_url = 'https://api.vk.com/method/';
$version = '5.28';

$filtered = $stats = array();
$type = $error = false;
$msg = '';
$total_count = $base_filtered_count = 0;
if ($url) {
	try {
		if (!preg_match('#^(https?://(m\.)?vk\.com/)?([\w\d_]+\?(z|w)=)?(wall|album)(-?[\d]+)_([\d]+)(\?rev=1)?$#i', $url, $match)) throw new Exception('Указана некорректная ссылка!');
		list(,,, $onpage, $param, $type, $owner_id, $item_id) = $match;
		if ($type == 'album') {
		} elseif ($type == 'wall') {
			$type = 'post';
		} else {
			throw new Exception('Ссылка неизвестного типа!');
		}
		$offset = 0;
		$count = $debug && $type != 'album' ? 100 : 1000;
		$user_ids = array();
		$photos = array();
		$continue_loop = false;
		do {
			if ($type == 'album') {
				$json = json_decode(file_get_contents(sprintf('%sphotos.get?owner_id=%d&album_id=%d&rev=1&extended=0&offset=%d&count=%d&version=%.2f', $base_url, $owner_id, $item_id, $offset, $count, $version)), true);
				if (!$json || !$json['response']) {
					@++$stats['error_photos_get'];
					continue;
				}
				$exclude_users_incremented = array();
				foreach ($json['response'] as $item) {
					if (!isset($item['user_id'])) {
						continue;
					}
					$uid = $item['user_id'];
					if (in_array($uid, $exclude_users)) {
						if (!in_array($uid, $exclude_users_incremented)) {
							@++$stats['excluded_users'];
							$exclude_users_incremented[$uid] = $uid;
						}
						continue;
					}
					if (!in_array($uid, $user_ids)) {
						$user_ids[] = $uid;
						++$total_count;
					}
					if (!isset($photos[$uid])) {
						$photos[$uid] = array();
					}
					$photos[$uid][] = $item;
				}
				$offset += $count;
				if ($debug) {
					continue;
				}
				$continue_loop = count($json['response']) == $count;
			} elseif ($type == 'post') {
				$json = json_decode(file_get_contents(sprintf('%slikes.getList?type=%s&owner_id=%d&item_id=%d&filter=likes&friends_only=0&extended=0&offset=%d&count=%d&version=%.2f', $base_url, $type, $owner_id, $item_id, $offset, $count, $version)), true);
				if (!$json || !$json['response']) {
					@++$stats['error_likes_get'];
					continue;
				}
				foreach ($json['response']['users'] as $uid) {
					if (in_array($uid, $exclude_users)) {
						@++$stats['excluded_users'];
						continue;
					}
					$user_ids[] = $uid;
					++$total_count;
				}
				$offset += $count;
				if ($debug) {
					continue;
				}
				$continue_loop = $total_count > $offset;
			}
		} while ($continue_loop);
		if ($user_ids) {
			$chunks = array_chunk($user_ids, 1000);
			$total_count = 0;
			foreach ($chunks as $user_ids) {
				$data = [
					'user_ids' => implode(',', $user_ids),
					'fields' => 'sex,bdate,city,photo_200_orig,photo_400_orig,photo_max_orig,online,last_seen,relation,status,personal',
					'version' => $version,
				];
				$ch = curl_init($base_url.'users.get');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
				$data = curl_exec($ch);
				$json_users = json_decode($data, true);
				curl_close($ch);
				$users = (array)$json_users['response'];
				if (!$users) {
					@++$stats['error_users_get'];
					continue;
				}
				$total_count += count($users);
				$uids = array();
				foreach ($users as $user) {
					$uids[] = $user['uid'];
					// banned
					if (@$user['blacklisted']) {
						@++$stats['blacklisted'];
						continue;
					}
					// empty avatar
					foreach ($user as $k => $v) {
						if (strpos($k, 'photo') !== false && strpos($v, 'camera') !== false) {
							@++$stats['avatar'];
							continue 2;
						}
					}
					if ($filter['sex'] && @$user['sex'] != $filter['sex']) {
						@++$stats['sex'];
						continue;
					}
					++$base_filtered_count;
					if ($filter['city'] == -1 && @$user['city']) {
						@++$stats['city'];
						continue;
					} elseif ($filter['city'] && @$user['city'] != $filter['city']) {
						@++$stats['city'];
						continue;
					}
					@list($d, $m, $y) = explode('.', @$user['bdate']);
					$age = $y ? date('Y') - $y : 0;
					$user['age'] = $age;
					if ($filter['age_require'] && !$age) {
						@++$stats['age_require'];
						continue;
					}
					if ($age && ($filter['age_min'] && $age < $filter['age_min'] || $filter['age_max'] && $age >= $filter['age_max'])) {
						@++$stats['age'];
						continue;
					}
					if ($filter['online'] && !@$user['online']) {
						@++$stats['online'];
						continue;
					}
					if ($filter['relations'] && !in_array(intval(@$user['relation']), $filter['relations'])) {
						@++$stats['relations'];
						continue;
					}
					$filtered[] = $user;
				}
				$users_not_found = array_diff($user_ids, $uids);
				if ($users_not_found) {
					@$stats['error_users_not_found'] += count($users_not_found);
				}
			}
		}
	} catch (Exception $e) {
		$error = true;
		$msg = $e->getMessage();
	}
}

function get_image($item, $ava = true) {
	$img = false;
	if ($ava) {
		$keys = array('photo_max_orig', 'photo_400_orig', 'photo_200_orig');
	} else {
		$keys = array('src_xxbig', 'src_xbig', 'src_big', 'src_small', 'src');
	}
	foreach ($keys as $k) {
		if (!isset($item[$k])) {
			continue;
		}
		$img = $item[$k];
		break;
	}
	return $img;
}

function get_time_diff($time) {
	$diff = time() - $time;
	if ($diff < 60) return 'меньше минуты';
	if ($diff < 60 * 10) return 'меньше 10 минут';
	if ($diff < 60 * 60) return 'меньше часа';
	if ($diff < 60 * 60 * 12) return 'меньше 12 часов';
	if ($diff < 60 * 60 * 24) return 'меньше суток';
	if ($diff < 60 * 60 * 24 * 3) return 'меньше 3 дней';
	if ($diff < 60 * 60 * 24 * 7) return 'меньше недели';
	if ($diff < 60 * 60 * 24 * 30) return 'меньше месяца';
	return 'больше месяца';
}

function htmlspecialchars_recursive($arr) {
	foreach ($arr as $k => $v) {
		if (is_array($v)) {
			$arr[$k] = htmlspecialchars_recursive($v);
		} else {
			$arr[$k] = is_bool($v) ? $v : htmlspecialchars($v);
		}
	}
	return $arr;
}

$stats_legend = array(
	'error_photos_get' => 'Ошибка: получение списка фотографий',
	'error_likes_get' => 'Ошибка: получение спика лайкнувших',
	'error_users_get' => 'Ошибка: получение списка пользователей',
	'error_users_not_found' => 'Ошибка: ВКонтакте не вернул пользователей',
	'excluded_users' => 'Исключены из выдачи',
	'blacklisted' => 'В черном списке',
	'avatar' => 'Нет аватара',
	'sex' => 'Пол',
	'city' => 'Город',
	'age_require' => 'Не указан возраст',
	'age' => 'Не подходит возраст',
	'online' => 'Не онлайн',
	'relations' => 'Не подходит по СП',
);

?>
<!DOCTYPE html>
<html>
<head>
	<title>Vk.com Filter</title>
	<meta charset="utf-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css" />
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap-theme.min.css" />
	<link media="all" rel="stylesheet" href="static/css/common.min.css" />
	<link media="only screen and (max-width: 400px)" rel="stylesheet" href="static/css/mobile.min.css" />
	<style>
		.items .item pre {
			max-height: <?=($filter['height'] + 21)?>px;
		}
		#fullImage {
			max-height: <?=$zoom_max_height?>px;
			max-width: <?=$zoom_max_width?>px;
		}
	</style>
</head>
<body>
<div class="container">

<nav class="navbar navbar-inverse">
	<a class="navbar-brand" href="?" title="На главную">VKFilter - фильтр пользователей соц сети VK.com</a>
	<a class="navbar-brand hide" href="?page=howto">Как пользоваться?</a>
</nav>

<div id="firstVisitBlock" class="alert alert-info hide" role="info">
	<button type="button" class="close" data-dismiss="info" aria-label="Закрыть"><span aria-hidden="true">&times;</span></button>
	<strong>Короткое описание проекта!</strong>
</div>

<? if ($debug) { ?>
<div class="alert alert-warning" role="warning">
	<strong>Включен режим отладки!</strong>
</div>
<? } ?>

<div class="panel panel-default">
	<div class="panel-body">
	<? if ($error) { ?>
		<div class="alert alert-danger" role="alert"><strong>Ошибка!</strong><? if ($msg) { ?><br /><?=htmlspecialchars($msg)?><? } ?></div>
	<? } ?>
	<form id="filter_frm" class="form-inline" method="get" onsubmit="$('#loading').removeClass('invisible'); return true;">
	<div class="form-group">
		<label for="frm_url">URL:</label>
		<input id="frm_url" name="url" class="form-control input-lg" value="<?=htmlspecialchars($url)?>"<? if(!$url) { ?> autofocus="true"<? } ?> required="true" size="60" autocomplete="off" placeholder="Введите URL ссылки" />
	</div>
	<br />
	Возраст
	<div class="form-group">
		<label for="frm_age_min">от</label>
		<input id="frm_age_min" class="form-control input-sm" name="filter[age_min]" type="number" value="<?=$filter['age_min']?>" style="width: 70px" />
	</div>
	<div class="form-group">
		<label for="frm_age_max">до</label>
		<input id="frm_age_max" class="form-control input-sm" name="filter[age_max]" type="number" value="<?=$filter['age_max']?>" style="width: 70px" />
	</div>
	лет
	<div class="form-group">
		<label for="frm_age_require">обязательно</label>
		<input id="frm_age_require" name="filter[age_require]" type="checkbox" value="1" <?=($filter['age_require'] ? ' checked="checked"' : '')?> />
	</div>
	.
	<br />
	<div class="form-group">
		<label for="frm_city">Город:</label>
		<select id="frm_city" name="filter[city]" class="form-control input-sm"><?
		foreach ($cities as $city_id => $city_title) {
			echo '<option value="'.$city_id.'"'.($city_id == $filter['city'] ? ' selected' : '').'>'.htmlspecialchars($city_title).'</option>'.PHP_EOL;
		}
		?></select>
	</div>
	<div class="form-group">
		<label for="frm_sex">Пол:</label>
		<select id="frm_sex" name="filter[sex]" class="form-control input-sm">
			<option value="0"<?=(!$filter['sex'] ? ' selected' : '')?>>Оба</option>
			<option value="1"<?=($filter['sex'] == 1 ? ' selected' : '')?>>Девушки</option>
			<option value="2"<?=($filter['sex'] == 2 ? ' selected' : '')?>>Парни</option>
		</select>
	</div>
	<div class="form-group">
		<label for="frm_online">Онлайн:</label>
		<input id="frm_online" name="filter[online]" type="checkbox" value="1" <?=($filter['online'] ? ' checked="checked"' : '')?> />
	</div>
	<br />
	<strong title="семейное положение">СП:</strong>
	<?
	foreach ($relations as $rel_id => $rel_title) {
		echo '<label class="checkbox-inline"><input name="filter[relations][]" type="checkbox" value="'.$rel_id.'" '.($filter['relations'] && in_array($rel_id, $filter['relations']) ? ' checked="checked"' : '').' /> '.$rel_title.'</label>';
	}
	?>
	<br />
	<button class="btn btn-success">
		Поиск
		<span id="loading" class="invisible glyphicon glyphicon-refresh glyphicon-refresh-animate"></span>
	</button>
	<button name="reset" class="btn btn-warning">Сбросить</button>
	<div class="form-group">
		<label for="frm_height">Размер:</label>
		<select id="frm_height" name="filter[height]" class="form-control input-sm"><?
			foreach ($heights as $h => $title) {
				echo '<option value="'.$h.'"'.($h == $filter['height'] ? ' selected' : '').'>'.htmlspecialchars($title).'</option>'.PHP_EOL;
			}
			if ($filter['height'] && !isset($heights[$filter['height']])) {
				echo '<option value="'.$filter['height'].'" selected>свой вариант ('.$filter['height'].')</option>'.PHP_EOL;
			}
		?></select>
	</div>
	<? if ($debug || isset($_COOKIE['debug'])) { ?>
	<div class="form-group">
		<label for="frm_debug">Debug:</label>
		<input id="frm_debug" name="debug" type="checkbox" value="1" <?=($debug ? ' checked="checked"' : '')?> />
	</div>
	<? } ?>

	</form>
</div>
</div>

<?
if ($stats) {
	?><p><a href="#" data-toggle="modal" data-target="#stats">статистика</a></p>
	<div id="stats" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;  </button>
					<h4 class="modal-title" id="myModalLabel">Статистика по фильтрам</h4>
				</div>
				<div class="modal-body">
					<?
					foreach ($stats_legend as $k => $title) {
						if (!isset($stats[$k])) {
							continue;
						}
						if ($k == 'excluded_users') {
							$add = ' (<a href="#" onclick="clearExcludedUsers(); return false;">очистить</a>)';
						} else {
							$add = '';
						}
						printf('<p>%s - <strong>%d</strong>%s</p>', $title, $stats[$k], $add);
					}
					?>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Закрыть</button>
				</div>
			</div>
		</div>
	</div>
	<?
}
if ($total_count) {
	$count = count($filtered);
	$sex_filter = $filter['sex'] ? ($filter['sex'] == 1 ? 'девушки' : 'парни') : 'прошли базовые проверки';
	?>
	<hr />
	<p class="text-muted">Результаты для: <a href="<?=htmlspecialchars($url)?>" target="_blank"><?=htmlspecialchars($url)?></a></p>
	<p class="text-muted">Всего: <?=$total_count?>, <?=$sex_filter?>: <?=($base_filtered_count.' ('.floor($base_filtered_count / $total_count * 100).'%)')?>, после фильтра: <?=($count.' ('.round($base_filtered_count ? $count / $base_filtered_count * 100 : 0, 2).'%)')?>.</p>
	<hr />
	<div class="items">
	<?
	foreach ($filtered as $user) {
		$uid = $user['uid'];
		?>
		<div class="item" id="user<?=$uid?>">
		<pre><a href="https://vk.com/id<?=$uid?>" target="_blank" title="<?=htmlspecialchars($user['status'])?>"><img src="<?=get_image($user)?>" style="max-height: <?=$filter['height']?>px;" /></a><?
			if ($type == 'album' && isset($photos[$uid])) {
				foreach ($photos[$uid] as $photo) {
					?><a href="https://vk.com/photo<?=($photo['owner_id'].'_'.$photo['pid'])?>" target="_blank" title="<?=htmlspecialchars($photo['text'].date(' - Y-m-d H:i', $photo['created']))?>"><img src="<?=get_image($photo, false)?>" style="max-height: <?=$filter['height']?>px;" data-height="<?=$photo['height']?>" data-width="<?=$photo['width']?>" /></a><?
				}
			}
		?></pre><br />
		<a href="#" class="remove" title="Удалить из выдачи" onclick="removeUser('<?=$uid?>'); return false;"><span class="glyphicon glyphicon-remove" aria-hidden="true"></span></a>
		<a href="https://vk.com/id<?=$uid?>" target="_blank" title="<?=htmlspecialchars(@$user['status'])?>"><?=(htmlspecialchars($user['first_name']).' '.htmlspecialchars($user['last_name']))?></a><?=($user['age'] ? ' ('.$user['age'].' лет)' : '').($user['online'] ? ' - online' : (@$user['last_seen'] && @$user['last_seen']['time'] ? ' - '.get_time_diff($user['last_seen']['time']) : '')).(isset($user['relation']) ? ' ('.$relations[intval($user['relation'])].')' : '').(!$filter['sex'] ? ($user['sex'] == '1' ? ' - девушка' : ($user['sex'] == '2' ? ' - парень' : '')) : '')?>
		<?=(@$user['status'] ? '<br />'.htmlspecialchars($user['status']) : '')?>
		<hr />
		</div>
	<?
	}
	?>
	</div>
	<?
}
?>
</div>
<img id="fullImage" src="about:blank" class="hide" />
<a id="ribbon" href="https://github.com/ihoru/VKFilter" target="_blank" title="Внести свой вклад в развитие проекта (откроется в новом окне)"><img style="position: absolute; top: 0; right: 0; border: 0;" src="https://camo.githubusercontent.com/38ef81f8aca64bb9a64448d0d70f1308ef5341ab/68747470733a2f2f73332e616d617a6f6e6177732e636f6d2f6769746875622f726962626f6e732f666f726b6d655f72696768745f6461726b626c75655f3132313632312e706e67" alt="Fork me on GitHub" data-canonical-src="https://s3.amazonaws.com/github/ribbons/forkme_right_darkblue_121621.png" /></a>
</body>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
<script src="static/js/jquery.cookie.min.js"></script>
<!--<script src="static/js/jquery.mousewheel.min.js"></script>-->
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/js/bootstrap.min.js"></script>
<script>
$(window).load(function() {
	<? if ($filter['height'] < $zoom_max_height) { ?>
	var fullImage = $('#fullImage');
	var calcNewPos = function(e, width, height) {
		var Y, X;
		if (!width || !height) {
			Y = e.pageY - 200;
			X = e.pageX + 30;
		} else {
			width = Math.min(width, <?=$zoom_max_height?>);
			height = Math.min(height, <?=$zoom_max_width?>);
			Y = Math.max(0, Math.round(e.pageY - height / 2));
			X = e.pageX + 30;
			if (X + width > e.view.innerWidth) {
				X = Math.max(0, e.pageX - 30 - width);
			}
		}
		return {
			top: Y + 'px',
			left: X + 'px'
		}
	};
	$('.items img').mouseover(function(e) {
		var el = $(e.currentTarget);
		fullImage.attr('src', el.attr('src'));
		fullImage.css(calcNewPos(e, el.data('width'), el.data('height')));
		fullImage.removeClass('hide');
	}).mousemove(function(e) {
		var el = $(e.currentTarget);
		fullImage.css(calcNewPos(e, el.data('width'), el.data('height')));
	}).mouseout(function(e) {
		fullImage.addClass('hide');
	});
	<? } ?>
//	$('.items .item pre').mousewheel(function(event) {
//		console.log(event.deltaX, event.deltaY, event.deltaFactor);
//	});
});
function removeUser(uid) {
	var exclude_users = $.cookie('exclude_users') || '';
	exclude_users += ',' + uid;
	$.cookie('exclude_users', exclude_users, {expires: 365});
	$('#user' + uid).remove();
}
function clearExcludedUsers() {
	var exclude_users = $.cookie('exclude_users') || '';
	var count = exclude_users.split(',').length;
	if (confirm('Вы уверены, что хотите очистить список пользователей, которые не появляются в выдаче (их около ' + count + ' шт)?')) {
		$.removeCookie('exclude_users');
	}
}
</script>
</html>
