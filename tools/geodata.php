<?php
/**
 * webtrees-geodata: geographic data for genealogists
 *
 * Copyright (C) 2016 Greg Roach
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

const LONG_OPTIONS = [
	'csv::',
	'file::',
  'lat::',
  'lon::',
  'place::',
  'txt::'
];

if (empty($argv[1])) {
	echo 'Usage: ', basename($argv[0]), ' [options]', PHP_EOL;
	echo 'Options are:', PHP_EOL;
	echo ' --file=FILE     Default is data.geojson', PHP_EOL;
	echo PHP_EOL;
	echo ' --place=PLACE --lat=LATITUDE --lon=LONGITUDE', PHP_EOL;
	echo PHP_EOL;
	echo ' --csv=FILE      Merge coordinates from a webtrees/googlemap CSV file', PHP_EOL;
	echo PHP_EOL;
	echo ' --txt=FILE      Merge places (without any coordinates) from a list', PHP_EOL;
	exit;
}

$options = getopt('', LONG_OPTIONS);

// The file to process
$file = $options['file'] ?? 'data.geojson';
if (is_dir($file)) {
	$file .= '/data.geojson';
}

// Load / create the file
if (file_exists($file)) {
	$geojson = json_decode(file_get_contents($file));
	if ($geojson === null) {
		var_dump(json_last_error(), json_last_error_msg());
		exit;
	}
} else {
	$geojson           = new stdClass;
	$geojson->type     = 'FeatureCollection';
	$geojson->features = [];

	file_put_contents($file, json_encode($geojson));
}

echo 'Processing: ', $file, PHP_EOL;

// Set coordinates for a place
$place   = $options['place'] ?? '';
$lat     = $options['lat'] ?? '';
$lon     = $options['lon'] ?? '';
if ($place !== '' && $lat !== '' && $lon !== '') {
	$lat   = latitude($lat);
	$lon   = longitude($lon);
	$found = false;
	foreach ($geojson->features as $feature) {
		if ($feature->id === $place) {
			echo 'Updating ', $place, '(', $lon, ', ', $lat, ')', PHP_EOL;
			$feature->geometry->coordinates = [$lat, $lon];
			$found = true;
		}
	}
	if (!$found) {
		echo 'Inserting ', $place, '(', $lon, ', ', $lat, ')', PHP_EOL;
		$feature             = new stdClass;
		$feature->type       = 'Feature';
		$feature->id         = $place;
		$feature->geometry   = new stdClass;
		$feature->geometry->type = 'Point';
		$feature->geometry->coordinates = [$lat, $lon];
		$feature->properties = new stdClass;
		$geojson->features[] = $feature;
	}
}

// Import (merge) a coordinates from a webtrees/googlemap .CSV file
$csv = $options['csv'] ?? '';
if ($csv !== '') {
	if (!file_exists($csv)) {
		echo 'ERROR: ', $csv, 'does not exist.', PHP_EOL;
		exit;
	}
	$fp = fopen($csv, 'r');
	$data = [];
	$max = 0;
	fgets($fp); // skip the header
	while (!feof($fp)) {
		$datum = fgetcsv($fp, 0, ';');
		$max = max($max, (int) $datum[0]);
		$data[] = $datum;
	}
	fclose($fp);

	$places = [];
	$errors = false;
	foreach ($data as $datum) {
		$place = $datum[$max + 1];
		$lon   = $datum[5];
		$lat   = $datum[6];
		if (is_numeric($lat) && is_numeric($lon)) {
			if (array_key_exists($place, $places)) {
				echo 'Duplicate place name: "', $place, '"', PHP_EOL;
				$errors = true;
			}
			$places[$place] = [longitude($lon), latitude($lat)];
		}
	}
	if ($errors) {
		exit;
	}

	foreach ($places as $id => $coords) {
		$geometry = new stdClass;
		$geometry->type = 'Point';
		$geometry->coordinates = $coords;

		// Update existing feature
		foreach ($geojson->features as $feature) {
			if ($feature->id === $id) {
				$feature->geometry       = $geometry;
				// Use typographic apostrophes in English
				$feature->properties->en = $feature->properties->en ?? strtr($id, ["'" => "’"]);
			}
		}
	}
}

// Import (merge) placenames form .txt file
$txt = $options['txt'] ?? '';
if ($txt !== '') {
	if (!file_exists($txt)) {
		echo 'ERROR: ', $txt, 'does not exist.', PHP_EOL;
		exit;
	}
	$fp     = fopen($txt, 'r');
	$places = [];
	while (!feof($fp)) {
		$place = trim(fgets($fp));
		if ($place !== '') {
			$places[$place] = true;
		}
	}
	fclose($fp);

	foreach ($geojson->features as $feature) {
		if (array_key_exists($feature->id, $places)) {
			unset($places[$feature->id]);
		}
	}
	foreach (array_keys($places) as $place) {
		$geometry              = new stdClass;
		$geometry->type        = 'Point';
		$geometry->coordinates = [0.0, 0.0];
		$feature           = new stdClass;
		$feature->id       = $place;
		$feature->geometry = $geometry;
		$geojson->features[] = $feature;
	}
}

// Pretty-print the file.  This minimises the changes in the GIT history.
// Remove redundant place names (i.e. same as the ID).
foreach ($geojson->features as $feature) {
	if (!isset($feature->id)) {
		$feature->id = '';
	}
	if (!isset($feature->properties) || !is_object($feature->properties)) {
		$feature->properties = new stdClass;
	}
	// Make sure all features have a type
	$feature->type = 'Feature';
	foreach ($feature->properties as $lang => $name) {
		if ($name === $feature->id) {
			unset($feature->properties->$lang);
		}
	}
}

// Sort alphabetically
usort($geojson->features, function (stdClass $x, stdClass $y): int { return strcmp($x->id, $y->id); }
);

$geojson = json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
// Restrict numbers to 5 decimal places.
$geojson = preg_replace_callback('/\b-?\d+\.?\d*\b/', function($x) { return round($x[0], 5); }, $geojson);
// Remove line-breaks in coordinates.
$geojson = preg_replace_callback('/(\[)([-.,0-9 \t\r\n]+)(\])/', function($x) { return $x[1] . preg_replace('/[ \t\r\n]+/s', ' ', $x[2]) . $x[3]; }, $geojson);
// Use tabs for indentation.
$geojson = str_replace('    ', "\t", $geojson);
file_put_contents($file, $geojson);

/**
 * Convert a user-supplied latitude into a decimal.
 *
 * @param string $latitude
 *
 * @return float
 */
function latitude(string $latitude):float {
	return angle_to_float($latitude, 'N+', 'S-');
}

/**
 * Convert a user-supplied longitude into a decimal.
 *
 * @param string $longitude
 *
 * @return float
 */
function longitude(string $longitude):float {
	return angle_to_float($longitude, 'E+', 'W-');
}

/**
 * Convert a user-supplied angle into a decimal.
 *
 * @return float
 */
function angle_to_float(string $angle, string $positive, string $negative): float {
	$sign = 1.0;
	$angle = trim($angle);
	$angle = trim($angle, $positive);
	if (trim($angle, $negative) !== $angle) {
		$angle = trim($angle, $negative);
		$sign = -1.0;
	}

	if (preg_match('/^([0-9.]+)\s*°?$/u', $angle, $match)) {
		$angle = $sign * $match[1];
	} elseif (preg_match('/^([0-9]+)\s*°\s*([0-9.]+)\s*[′\']?\s*$/u', $angle, $match)) {
		$angle = $sign * ($match[1] + $match[2] / 60.0);
	} elseif (preg_match('/^([0-9]+)\s*°\s*([0-9]+)\s*[′\']\s*([0-9.]+)\s*[″"]?\s*$/u', $angle, $match)) {
		$angle = $sign * ($match[1] + $match[2] / 60.0 + $match[3] / 3600);
	} else  {
		echo 'ERROR: the angle ', $angle, ' is not recognised.', PHP_EOL;
		exit;
	}

	return round($angle, 5);
}
