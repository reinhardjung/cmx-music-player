<?php namespace MyMusicPlayer; defined('ABSPATH') or die('Oxytocin!');

/**
 * Plugin Name: CLOUD Meister - Music Player
 * Plugin URI: https://cloudmeister.ch/cmx-music-player/
 * Description: Ein einfacher Musik-Player für lokale Musikdateien [music_player]
 * Version:  25.0611.1965-5
 * Author: CLOUD Meister
 * Author URI: https://cloudmeister.ch/
 * Domain Path: /languages
 * License: GPL2
 * Requires PHP: 8.2
 * Requires at least: 6.7.1
 */

define('CMX_MUSIC_DIR', WP_CONTENT_DIR . '/uploads/music');
define('CMX_MUSIC_URL', content_url('/uploads/music'));


add_action('init', __NAMESPACE__ . '\\cmx_generate_playlists_per_folder');
function cmx_generate_playlists_per_folder() {
	$base_dir = CMX_MUSIC_DIR;

	if (!is_dir($base_dir)) return;

	$folders = scandir($base_dir);
	foreach ($folders as $folder) {
		if ($folder === '.' || $folder === '..') continue;

		$folder_path = $base_dir . DIRECTORY_SEPARATOR . $folder;
		if (!is_dir($folder_path)) continue;

		$playlist_file = $folder_path . DIRECTORY_SEPARATOR . $folder . '.m3u8';
		if (file_exists($playlist_file)) continue;

		$files = scandir($folder_path);
		$audio_files = array_filter($files, function ($file) use ($folder_path) {
			$ext = pathinfo($file, PATHINFO_EXTENSION);
			return is_file($folder_path . DIRECTORY_SEPARATOR . $file) && in_array(strtolower($ext), ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'mp4']);
		});

		if (empty($audio_files)) continue;

		$playlist_content = "#EXTM3U\n";
		foreach ($audio_files as $file) $playlist_content .= $file . "\n";

		file_put_contents($playlist_file, $playlist_content);
	}
}


add_shortcode('music_player', function () {
	$base_dir = CMX_MUSIC_DIR;
	$base_url = CMX_MUSIC_URL;

	$playlist_data = [];
	$counter = 0;

	$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base_dir));
	foreach ($rii as $file) {
		if ($file->isDir() || $file->getExtension() !== 'm3u8') continue;

		$playlist_path = $file->getPathname();
		$relative_path = str_replace($base_dir, '', $file->getPath());
		$folder_url = rtrim($base_url . $relative_path, '/');

		$playlist_name = str_replace(['_', '-'], ' ', basename($file->getFilename(), '.m3u8'));
		$playlist_id = 'playlist_' . (++$counter);

		$lines = file($playlist_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$tracks = array_filter($lines, fn($line) => substr(trim($line), 0, 1) !== '#');
		sort($tracks, SORT_NATURAL | SORT_FLAG_CASE);

		if (empty($tracks)) continue;

		$track_urls = array_map(function ($track) use ($folder_url) {
			return $folder_url . '/' . rawurlencode(basename(trim($track)));
		}, $tracks);

		$playlist_data[] = ['id' => $playlist_id,'name' => $playlist_name,'count' => count($track_urls),'json' => json_encode($track_urls, JSON_UNESCAPED_SLASHES)];
	}

	if (empty($playlist_data)) return '<p>Keine Playlists gefunden.</p>';

	usort($playlist_data, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

	$output = '<div style="display:flex; flex-wrap:wrap; gap:2em;">';
	foreach ($playlist_data as $p) {
		$output .= '<div style="flex:1 1 45%; min-width:300px;">';
		$output .= '<h3>🎧 <b>' . esc_html($p['name']) . '</b> - ' . $p['count'] . '</h3>';
		$output .= '<div id="' . $p['id'] . '_title" style="font-style:italic; margin-bottom:0.2em; display:none;"></div>';
		$output .= '<audio id="' . $p['id'] . '_player" controls style="width:100%; max-width:100%;"></audio>';
		$output .= '<div id="' . $p['id'] . '_data" data-tracks=\'' . esc_attr($p['json']) . '\'></div>';
		$output .= '</div>';
	}
	$output .= '</div>';
	$output .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
	document.querySelectorAll('[id$="_data"]').forEach(function(container) {
		const playerId = container.id.replace('_data', '_player');
		const titleId  = container.id.replace('_data', '_title');
		const player   = document.getElementById(playerId);
		const title    = document.getElementById(titleId);
		const urls     = JSON.parse(container.getAttribute('data-tracks'));
		let index      = 0;

		function setTrack(i) {
			if (i >= 0 && i < urls.length) {
				player.src = urls[i];
				title.style.display = 'none';
			}
		}

		if (urls.length > 0) setTrack(index);

		player.addEventListener('play', function () {
			if (player.src) {
				const filename = decodeURIComponent(player.src.split('/').pop());
				const nameWithoutExt = filename.replace(/\.[^/.]+$/, '');
				title.innerText = nameWithoutExt;
				title.style.display = 'block';
			}
		});

		player.addEventListener('ended', function() {
			index++;
			if (index < urls.length) {
				setTrack(index);
				player.play().catch(e => console.warn('Autoplay blockiert:', e));
			}
		});
	});
});
</script>
HTML;
	return $output;
});


add_filter('render_block', function ($block_content, $block) {
  return str_replace('is-layout-constrained', '', $block_content);
}, 10, 2);


add_action('template_redirect', __NAMESPACE__ . '\\cmx_check_and_delete_playlists');
function cmx_check_and_delete_playlists() {
	if (!isset($_GET['init'])) return;

	global $post;
	if (!isset($post->post_content)) return;

	if (!has_shortcode($post->post_content, 'music_player')) return;

	$base_dir = CMX_MUSIC_DIR;
	if (!is_dir($base_dir)) return;

  $iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::CHILD_FIRST
  );

	foreach ($iterator as $file) {
		if ($file->isFile() && preg_match('/\.m3u8?$/i', $file->getFilename())) @unlink($file->getPathname());
  }

	wp_redirect(remove_query_arg('init', get_permalink()));  // Nach erfolgreichem Löschen zurück zum Player (ohne "?init")
}
