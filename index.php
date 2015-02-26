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
	'city' => 1,
	'sex' => 1,
	'age_min' => 18,
	'age_max' => 28,
	'age_require' => false,
	'online' => false,
	'relations' => array(),
);
$url = isset($_REQUEST['url']) ? strval($_REQUEST['url']) : '';
$filter = isset($_REQUEST['filter']) && !isset($_REQUEST['reset']) ? $_REQUEST['filter'] : array();
$filter = array_merge($filter_default, $filter);
foreach ($filter_default as $k => $v) {
	if (is_numeric($v)) {
		$filter[$k] = intval($filter[$k]);
	} elseif (is_bool($v)) {
		$filter[$k] = (bool)($filter[$k]);
	}
	if ($k == 'sex') {
		$filter[$k] = max(0, min(2, $filter[$k]));
	}
}
$filter = htmlspecialchars_recurcive($filter);
$cities = array(
	'1' => 'Москва',
	'72' => 'Краснодар',
	'106' => 'Оренбург',
);
$relations = array(
	0 => 'none selected',
	1 => 'single',
	6 => 'actively searching',
	5 => 'it\'s complicated',
	2 => 'in a relationship',
	7 => 'in love',
	3 => 'engaged',
	4 => 'married',
);

$base_url = 'https://api.vk.com/method/';
$version = '5.28';

$filtered = array();
$type = $error = false;
$msg = '';
$total_count = $girls_count = 0;
if ($url) {
	try {
		if (!preg_match('#^(https?://vk\.com/)?([\w\d_]+\?(z|w)=)?(wall|album)(-?[\d]+)_([\d]+)(\?rev=1)?$#i', $url, $match)) throw new Exception('Link does not match regexp.');
		list(,, $onpage, $param, $type, $owner_id, $item_id) = $match;
		if ($type == 'album') {
		} elseif ($type == 'wall') {
			$type = 'post';
		} else {
			throw new Exception('Unknown link type');
		}
		$offset = 0;
		$count = 1000;
		$user_ids = array();
		$photos = array();
		$continue_loop = false;
		do {
			if ($type == 'album') {
				$json = json_decode(file_get_contents(sprintf('%sphotos.get?owner_id=%d&album_id=%d&rev=1&extended=0&offset=%d&count=%d&version=%f', $base_url, $owner_id, $item_id, $offset, $count, $version)), true);
				if (!$json || !$json['response']) continue;
				foreach ($json['response'] as $item) {
					if (!isset($item['user_id'])) {
						continue;
					}
					$uid = $item['user_id'];
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
//				continue;//TODO:
				$continue_loop = count($json['response']) == $count;
			} elseif ($type == 'post') {
				$json = json_decode(file_get_contents(sprintf('%slikes.getList?type=%s&owner_id=%d&item_id=%d&filter=likes&friends_only=0&extended=0&offset=%d&count=%d&version=%f', $base_url, $type, $owner_id, $item_id, $offset, $count, $version)), true);
				if (!$json || !$json['response']) continue;
				foreach ($json['response']['users'] as $uid) {
					$user_ids[] = $uid;
				}
				$total_count = $json['response']['count'];
				$offset += $count;
//				continue;//TODO:
				$continue_loop = $total_count > $offset;
			}
		} while ($continue_loop);
		if ($user_ids) {
			$chunks = array_chunk($user_ids, 1000);
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
				foreach ($users as $user) {
					// banned
					if (@$user['blacklisted']) {
						continue;
					}
					if ($filter['sex'] && @$user['sex'] != $filter['sex']) {
						continue;
					}
					++$girls_count;
					if ($filter['city'] && @$user['city'] != $filter['city']) {
						continue;
					}
					@list($d, $m, $y) = explode('.', @$user['bdate']);
					$age = $y ? date('Y') - $y : 0;
					$user['age'] = $age;
					if ($filter['age_require'] && !$age) {
						continue;
					}
					if ($age && ($filter['age_min'] && $age < $filter['age_min'] || $filter['age_max'] && $age >= $filter['age_max'])) {
						continue;
					}
					if ($filter['online'] && !@$user['online']) {
						continue;
					}
					if ($filter['relations'] && !in_array(intval(@$user['relation']), $filter['relations'])) {
						continue;
					}
					// empty avatar
					foreach ($user as $k => $v) {
						if (strpos($k, 'photo') !== false && strpos($v, 'camera') !== false) {
							continue 2;
						}
					}
					$filtered[] = $user;
				}
//				break;//TODO:
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
	if ($diff < 60) return 'less than minute';
	if ($diff < 60 * 10) return 'less than 10 minutes';
	if ($diff < 60 * 60) return 'less than hour';
	if ($diff < 60 * 60 * 12) return 'less than 12 hours';
	if ($diff < 60 * 60 * 24) return 'less than day';
	if ($diff < 60 * 60 * 24 * 3) return 'less than 3 days';
	if ($diff < 60 * 60 * 24 * 7) return 'less than week';
	if ($diff < 60 * 60 * 24 * 30) return 'less than month';
	return '';
}

function htmlspecialchars_recurcive($arr) {
	foreach ($arr as $k => $v) {
		if (is_array($v)) {
			$arr[$k] = htmlspecialchars_recurcive($v);
		} else {
			$arr[$k] = is_bool($v) ? $v : htmlspecialchars($v);
		}
	}
	return $arr;
}

?>
<!DOCTYPE html>
<html>
<head>
	<title>Vk.com Filter</title>
	<meta charset="utf-8" />
	<link media="all" rel="stylesheet" href="static/css/bootstrap.min.css" />
	<link media="all" rel="stylesheet" href="static/css/common.css" />
	<script src="static/js/bootstrap.min.js"></script>
</head>
<body>
<div class="container">

<nav class="navbar navbar-inverse">
    <a class="navbar-brand" href="#">VK Filter</a>
</nav>

<div class="panel panel-default">
    <div class="panel-body">
	<form class="form-inline">
	<div class="form-group">
		<label for="frm_url">URL:</label>
		<input id="frm_url" name="url" class="form-control" value="<?=htmlspecialchars($url)?>"<? if(!$url) { ?> autofocus="true"<? } ?> required="true" size="40" autocomplete="off" placeholder="Enter URL" />
	</div>
	<?
	if ($error) {
		?>
		<div class="alert alert-danger" role="alert"><strong>Wrong URL</strong> (i.e.: https://vk.com/wall-21090314_139626).</div>
		<? if ($msg) { ?><div class="alert alert-danger" role="alert"><?=htmlspecialchars($msg)?></div><? } ?>
		<?
	}
	?>
	<div class="form-group">
		<label for="frm_age_require">Require age:</label>
		<input id="frm_age_require" name="filter[age_require]" type="checkbox" value="1" <?=($filter['age_require'] ? ' checked="checked"' : '')?> />
	</div>
	<div class="form-group">
		<label for="frm_age_min">From:</label>
		<input id="frm_age_min" name="filter[age_min]" type="number" value="<?=$filter['age_min']?>" style="width: 40px" />
	</div>
	<div class="form-group">
		<label for="frm_age_max">To:</label>
		<input id="frm_age_max" name="filter[age_max]" type="number" value="<?=$filter['age_max']?>" style="width: 40px" />
	</div>
	<div class="form-group">
		<label for="frm_online">Online:</label>
		<input id="frm_online" name="filter[online]" type="checkbox" value="1" <?=($filter['online'] ? ' checked="checked"' : '')?> />
	</div>
	<div class="form-group">	
		<label for="frm_city">City:</label>
		<select id="frm_city" name="filter[city]" class="form-control"><?
		foreach ($cities as $city_id => $city_title) {
			echo '<option value="'.$city_id.'"'.($city_id == $filter['city'] ? ' selected' : '').'>'.htmlspecialchars($city_title).'</option>'.PHP_EOL;
		}
	?>
		</select>
	</div>
	<div class="form-group">
		<label for="frm_sex">Sex:</label>
		<select id="frm_sex" name="filter[sex]" class="form-control">
			<option value="0"<?=(!$filter['sex'] ? ' selected' : '')?>>Both</option>
			<option value="1"<?=($filter['sex'] == 1 ? ' selected' : '')?>>Female</option>
			<option value="2"<?=($filter['sex'] == 2 ? ' selected' : '')?>>Male</option>
		</select>
	</div>
	<br />
	Relationship status:
	<?
	foreach ($relations as $rel_id => $rel_title) {
		echo '<label class="checkbox-inline"><input name="filter[relations][]" type="checkbox" value="'.$rel_id.'" '.($filter['relations'] && in_array($rel_id, $filter['relations']) ? ' checked="checked"' : '').' /> '.$rel_title.'</label>';
	}
	?>
	<br />
	<button name="reset" class="btn btn-warning">Reset</button>
	<button class="btn btn-success">Search</button>
</form>
</div>
</div>
<?
if ($total_count) {
	$count = count($filtered);
	printf('<hr />Total: %d, girls: %d (%d%%), filtered: %d (%.2f%%)<br /><a href="%s" target="_blank">%s</a><hr />', $total_count, $girls_count, $girls_count / $total_count * 100, $count, $girls_count ? $count / $girls_count * 100 : 0, htmlspecialchars($url), htmlspecialchars($url));
	foreach ($filtered as $user) {
		$uid = $user['uid'];
		?>
		<a href="https://vk.com/id<?=$uid?>" target="_blank" title="<?=htmlspecialchars($user['status'])?>"><img src="<?=get_image($user)?>" style="max-height: 500px;" /></a>
			<?
			if ($type == 'album' && isset($photos[$uid])) {
				foreach ($photos[$uid] as $photo) {
					?><a href="https://vk.com/photo<?=($photo['owner_id'].'_'.$photo['pid'])?>" target="_blank"><img src="<?=get_image($photo, false)?>" style="max-height: 500px;" /></a><?
				}
			}
			?>
		<br />
		<a href="https://vk.com/id<?=$uid?>" target="_blank" title="<?=htmlspecialchars($user['status'])?>"><?=(htmlspecialchars($user['first_name']).' '.htmlspecialchars($user['last_name']))?></a><?=($user['age'] ? ' ('.$user['age'].' years)' : '').($user['online'] ? ' - online' : ($user['last_seen'] && $user['last_seen']['time'] ? ' - '.get_time_diff($user['last_seen']['time']) : '')).(isset($user['relation']) ? ' ('.$relations[intval($user['relation'])].')' : '')?>
		<?=(@$user['status'] ? '<br />'.htmlspecialchars($user['status']) : '')?>
		<hr />
	<?
	}
}
?>
</div>
</body>
</html>
