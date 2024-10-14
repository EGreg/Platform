<?php

class   Q_Translate_Google {

	function __construct(Q_Translate $parent)
	{
		$this->apiKey = Q_Config::get('Q', 'translate', 'google', 'key', '');
		$this->parent = $parent;
	}

	function saveAll() {
		$parts = preg_split("/(_|-)/", $this->parent->options['source']);
		$fromLang = $parts[0];
		$locale = count($parts) > 1 ? $parts[1] : null;
		$in = $this->parent->getSrc($fromLang, $locale, true);
		$useLocale = Q_Config::get('Q', 'text', 'useLocale', false);
		foreach ($this->parent->locales as $toLang => $localeNames) {
			$b1 = "\033[1m";
			$b2 = "\033[0m";
			echo $b1."Processing $fromLang->$toLang" . $b2;
			if ($useLocale) {
				echo '  (' . implode(' ', $localeNames) . ')';
			}
			echo PHP_EOL;
			if ($toLang !== $fromLang) {
				$out = $this->parent->getSrc($toLang, $locale, false);
				$toRemove = $this->parent->toRemove($out);
				foreach ($toRemove as $n => $parts) {
					unset($res[$n]);
				}
				$res = $this->translate($fromLang, $toLang, $in, $out, $toRemove, 100);
			} else if ($this->parent->options['out']) {
				$res = $in;
				$toRemove = $this->parent->toRemove($in);
				foreach ($toRemove as $n => $parts) {
					unset($res[$n]);
				}
			}
			if (isset($res) and is_array($res)) {
				$this->saveJson($toLang, $res, $jsonFiles);
			}
			if (!$useLocale) {
				continue;
			}
			if (!empty($this->parent->options['in'])
			&& !empty($this->parent->options['out'])
			&& ($fromLang == $toLang)
			&& ($this->parent->options['in'] === $this->parent->options['out'])) {
				foreach ($localeNames as $localeName) {
					$this->saveLocale($toLang, $localeName, $res, $jsonFiles, $toRemove);
				}
				continue;
			}
			if (isset($this->parent->options['locales'])) {
				foreach ($localeNames as $localeName) {
					$this->saveLocale($toLang, $localeName, $res, $jsonFiles, $toRemove);
				}
			}
		}
	}
	
	private function saveLocale($lang, $locale, $res, $jsonFiles, $toRemove)
	{
		foreach ($jsonFiles as $dirname => $content) {
			$directory = $this->parent->createDirectory($dirname);
			$langFile = $directory . DS . "$lang.json";
			$localeFile = $directory . DS . "$lang-$locale.json";
			if (file_exists($localeFile)) {
				$arr = $content;
				$tree = new Q_Tree();
				$tree->load($localeFile);
				$tree->merge($arr);
				foreach ($toRemove as $n => $parts) {
					call_user_func_array(array($tree, 'clear'), $parts);
				}
				$tree->save($localeFile, array(), null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			} else {
				copy($langFile, $localeFile);
			}
		}
	}

	private function saveJson($lang, $data, &$jsonFiles)
	{
		$jsonFiles = array();
		foreach ($data as $d) {
			$dirname = $d['dirname'];
			$arr =& $jsonFiles[$dirname];
			if (!$arr or !sizeof($arr)) {
				$arr = array();
			}
			array_push($d['key'], $d['value']);
			$tree = new Q_Tree($arr);
			$tree->merge(Q_Translate::arrayToBranch($d['key']));
		}
		$filenames = array();
		foreach ($jsonFiles as $dirname => $content) {
			$dir = $this->parent->createDirectory($dirname);
			$filename = $this->parent->joinPaths($dir, $lang . '.json');
			$filenames[] = $filename;
			$fp = fopen($filename, 'w');
			fwrite($fp, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			fclose($fp);
		}
		return $filenames;
	}

	private function replaceTagsByNumbers($data, $startNumber = 999) {
		foreach ($data as $k => &$v) {
			if (!preg_match_all("/(?<=\{{)(?:.*?)(?!.\}})(?=}})/", $v['value'], $matches)) {
				continue;
			}
			$j = 0;
			foreach($matches[0] as $search) {
				$index = $j + $startNumber;
				$text = "<i translate='no'>$index</i>";
				$v['value'] = str_replace('{{'.$search.'}}', $text, $v['value']);
				$j++;
			}
			$v['tags'] = $matches[0];
		}
		return $data;
	}

	private function revertTags($data, $startNumber = 999) {
		foreach ($data as $k => &$d) {
			if (empty($d['tags'])) {
				continue;
			}
			$j = 0;
			foreach($d['tags'] as $tag) {
				$index = $j + $startNumber;
				$d['value'] = str_replace("<i translate='no'>$index</i>", '{{'.$tag.'}}', $d['value']);
				$j++;
			}
		};
		return $data;
	}

	private function translate($fromLang, $toLang, $in, &$out = array(), $toRemove = array(), $chunkSize = 100)
	{
		$in = $this->replaceTagsByNumbers($in);
		$in2 = array();
		$o = $this->parent->options;
		$rt = Q::ifset($o, 'retranslate', Q::ifset($o, 'r', array()));
		$rta = Q::ifset($o, 'retranslate-all', Q::ifset($o, 'a', null));
		$translateAll = isset($rta);
		$rt = is_array($rt) ? $rt : array($rt);
		foreach ($in as $n => $v) {
			$key = $v['dirname'] . "\t" . implode("\t", $v['key']);
			$key2 = implode("/", $v['key']);
			$doIt = false;
			if (empty($out[$key]) or $translateAll) {
				$doIt = true;
			} else {
				foreach ($rt as $v2) {
					$parts = Q_Utils::explodeEscaped('/', $v2);
					foreach ($parts as $i => $p) {
						if ($v['key'][$i] !== $p) {
							continue 2;
						}
					}
					$doIt = true;
				}
			}
			if ($doIt) {
				$v['originalKey'] = $n;
				$in2[] = $v;
			}
		}
		$translations = array();
		if (!$toRemove and !$in2) {
			return array();
		}
		$res = $out;
		if (!$in2) {
			return $res;
		}
		$chunks = array_chunk($in2, $chunkSize);
		$count = 0;
		foreach ($chunks as $chunk) {
			$qArr = array_map(function ($item) {
				return $item['value'];
			}, $chunk);
			print "Requesting google translation api\n";
			$url = 'https://translation.googleapis.com/language/translate/v2?key=' . $this->apiKey;
			$postFields = array(
				"q" => $qArr, 
				"source" => $fromLang, 
				"target" => $toLang, 
				"format" => $this->parent->options['google-format']
			);
			// $content = Q_Utils::multipartFormData($postFields);
			$json = Q_Utils::post($url, $postFields, null, array(), array(
				'Expect: 100-Continue',
				'Content-Type: multipart/form-data'
			));
			$response = json_decode($json, true);
			if (!$response) {
				throw new Q_Exception ("Bad translation response");
			}
			if (!empty($response['error']['message'])) {
				if (Q::startsWith($response['error']['message'], 'Bad language pair')) {
					echo "Skipping: " . $response['error']['message'] . PHP_EOL;
					return false;
				} else {
					$more = "Make sure you have Q/translate/google/key specified.";
					throw new Q_Exception($response['error']['message'] . PHP_EOL . $more);
				}
			}
			$count += sizeof($chunk);
			echo "Translated " . $count . " queries of " . $toLang . "\n";
			$translations = array_merge($translations, $response['data']['translations']);
		}
		foreach ($in2 as $n => $d) {
			$originalKey = $d['originalKey'];
			$res[$originalKey] = $d;
			$res[$originalKey]['value'] = $translations[$n]['translatedText'];
		}
		return $this->revertTags($res);
	}

	public $apiKey;
	public $parent;

}