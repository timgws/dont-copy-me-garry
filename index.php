<?php

require "TermExtractor.php";
require "cleanhtml.php";

$json = array();

$hasSource = false;
if (isset($_POST['source'])) {
    $hasSource = true;
} elseif (isset($_POST['sourceurl'])) {
    /* download the source, set the _POST var, and set the source as being there... */
    $_POST['source'] = curl($_GET['sourceurl']);
    $hasSource = true;
}

if (isset($_GET['articleurl'])) {
    $_POST['article'] = curl($_GET['sourceurl']);
}

if (!$hasSource or !isset($_POST['article'])) {
    json_error(428, "Incorrect arguments. Send me your information please!");
}

$source = cleanText($_POST['source']);
$article= cleanText($_POST['article']);

$excluded = array();
$terms = getTerms($source);
if (count($terms) > 0) {
    foreach($terms as $term) {
        $excluded[] = $term[0];

        $source = str_replace($term[0], '', $source);
        $article= str_replace($term[0], '', $article);
    }
}

$source = finalClean($source);
$article= finalClean($article);


$tokens_source = ngram($source, 3);
$tokens_article= ngram($article,3);

$cc = closeCompare($tokens_source, $tokens_article);
$json = $cc;
$json['error'] = false;
$json['excluded_words'] = $excluded;

var_dump($json);
echo json_encode($json);

function json_error($code, $msg) {
    if ($code == 428) {
        header("HTTP/1.1 428 Precondition Required");
    }

    header("Content-Type: application/json");
    echo json_encode(array('error' => true, 'message' => $msg));
    exit;
}

#_____________________
# parse_cli($string) /
function parse_cli($string) {
    $state = 'space';
    $previous = '';     // stores current state when encountering a backslash (which changes $state to 'escaped', but has to fall back into the previous $state afterwards)
    $out = array();     // the return value
    $word = '';
    $type = '';         // type of character
    // array[states][chartypes] => actions
    $chart = array(
        'space'        => array('space'=>'',   'quote'=>'q',  'doublequote'=>'d',  'backtick'=>'b',  'backslash'=>'ue', 'other'=>'ua'),
        'unquoted'     => array('space'=>'w ', 'quote'=>'a',  'doublequote'=>'a',  'backtick'=>'a',  'backslash'=>'e',  'other'=>'a'),
        'quoted'       => array('space'=>'a',  'quote'=>'w ', 'doublequote'=>'a',  'backtick'=>'a',  'backslash'=>'e',  'other'=>'a'),
        'doublequoted' => array('space'=>'a',  'quote'=>'a',  'doublequote'=>'w ', 'backtick'=>'a',  'backslash'=>'e',  'other'=>'a'),
        'backticked'   => array('space'=>'a',  'quote'=>'a',  'doublequote'=>'a',  'backtick'=>'w ', 'backslash'=>'e',  'other'=>'a'),
        'escaped'      => array('space'=>'ap', 'quote'=>'ap', 'doublequote'=>'ap', 'backtick'=>'ap', 'backslash'=>'ap', 'other'=>'ap'));
    for ($i=0; $i<=strlen($string); $i++) {
        $char = substr($string, $i, 1);
        $type = array_search($char, array('space'=>' ', 'quote'=>'\'', 'doublequote'=>'"', 'backtick'=>'`', 'backslash'=>'\\'));
        if (! $type) $type = 'other';
        if ($type == 'other') {
            // grabs all characters that are also 'other' following the current one in one go
            preg_match("/[ \'\"\`\\\]/", $string, $matches, PREG_OFFSET_CAPTURE, $i);
            if ($matches) {
                $matches = $matches[0];
                $char = substr($string, $i, $matches[1]-$i); // yep, $char length can be > 1
                $i = $matches[1] - 1;
            }else{
                // no more match on special characters, that must mean this is the last word!
                // the .= hereunder is because we *might* be in the middle of a word that just contained special chars
                $word .= substr($string, $i);
                break; // jumps out of the for() loop
            }
        }
        $actions = $chart[$state][$type];
        for($j=0; $j<strlen($actions); $j++) {
            $act = substr($actions, $j, 1);
            if ($act == ' ') $state = 'space';
            if ($act == 'u') $state = 'unquoted';
            if ($act == 'q') $state = 'quoted';
            if ($act == 'd') $state = 'doublequoted';
            if ($act == 'b') $state = 'backticked';
            if ($act == 'e') { $previous = $state; $state = 'escaped'; }
            if ($act == 'a') $word .= $char;
            if ($act == 'w') { $out[] = $word; $word = ''; }
            if ($act == 'p') $state = $previous;
        }
    }
    if (strlen($word)) $out[] = $word;
    return $out;
}

function cleanText($string) {
    // remove utf8 characters that could be represented in ascii
    $string = removeAccents($string);

    // replace multiple spaces and newlines.
    mb_internal_encoding('utf-8');
    $string = trim(str_replace("\n", " ", $string));
    $string = preg_replace('/(?|( )+)/', '$1', $string);

    // We need to clean out article_text
    $clean = new CleanHTML($string);
    $clean = $clean->Clean(array('strip' => true));
    $clean = strip_tags($clean);
    $clean = CleanHTML::changeQuotes($clean);

    return $clean;
}

function finalClean($string) {
    mb_internal_encoding('utf-8');
    $string = mb_strtolower( $string );
    $string = preg_replace("/\pP/", "", $string);
    return $string;
}

function ngrams($word, $n = 3) {
    $ngrams = array();
    $len = strlen($word);
    for($i = 0; $i < $len; $i++) {
        if($i > ($n - 2)) {
            $ng = '';
            for($j = $n-1; $j >= 0; $j--) {
                $ng .= $word[$i-$j];
            }
            $ngrams[] = $ng;
        }
    }
    return $ngrams;
}

function getTerms($text, $limit = true, $sortByOccurance = false) {
    $extractor = new TermExtractor();
    $keywords = $extractor->extract($text);
    $words = array();
    $occur = array();
    $number = array();

    foreach($keywords as $key=>$kw) {
        $words[$key] = $kw[0];
        $occur[$key] = $kw[1];
        $number[$key]= $kw[2];
    }

    if ($sortByOccurance) {
        array_multisort($occur, SORT_DESC, $words, SORT_ASC, $keywords);
    } else {
        array_multisort($number, SORT_DESC, $occur, SORT_DESC, $words, SORT_ASC, $keywords);
    }

    if ($limit == true) {
        $keywords = array_slice($keywords, 0, 7);
        return $keywords;
    }

    return $keywords;
}

function ngram($string, $n = null) {
    if (is_null($n))
        $n = 2;

    // Split up all the words...
    $words = explode(' ', $string);
    $numberWords = count($words);

    $ngrams = array();
    for ($i = 0; $i < $numberWords; $i++) {
        $ngrams[] = implode(array_slice($words, $i, $n), " ");
    }

    return $ngrams;
}

function closeCompare($ngram1, $ngram2, $n = 2) {
    $nc1 = count($ngram1);
    $nc2 = count($ngram2);

    $matches = 0;
    $matchInRow = 0;
    $mostInRow  = 0;
    $match = array();
    $score = 0;

    $ngs = array_intersect( $ngram2, $ngram1 );
    $ngpositions = array_keys($ngs);
    $foundWords = count($ngs);

    $last = -1;
    $mostInRow = 0;
    $inRow = 1;
    $countedLast = false;
    $lastRow = 0;
    $score = 0;
    for($i = 0; $i < $foundWords+1; $i++) {
        $pos = $ngpositions[$i];
        if ($last == $pos-1) {
            $last = $pos;
            $inRow++;
            $countedLast = false;
        } else {
            $last = $pos;

            if ($mostInRow < $inRow) {
                $mostInRow = $inRow;
            }

            $lastRow = $inRow;

            $inRow = 1;
            $countedLast = true;
        }

        if ($countedLast && $lastRow > 1) {
            $score += $lastRow;
        }
    }

    if ($mostInRow < $inRow) {
        $mostInRow = $inRow;
    }

    return array(
            'matches' => $matches,
            'score' => $score,
            'check_count' => $nc2,
            'most_in_row' => $mostInRow,
            'percentage' => (($score/$nc2))*100
            );
    return (($score/$nc1))*100 ;
}

function compare($ngram1, $ngram2) {
    $sum = array_unique(array_merge($ngram1, $ngram2));
    $intersection = array_intersect($ngram1, $ngram2);
    $score = count($intersection) / count($sum);
    return $score;
}

function removeAccents($str) {
  $a = array('À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ', 'Ç', 'È', 'É', 'Ê', 'Ë', 'Ì', 'Í', 'Î', 'Ï', 'Ð', 'Ñ', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ý', 'ß', 'à', 'á', 'â', 'ã', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', 'ø', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'Ā', 'ā', 'Ă', 'ă', 'Ą', 'ą', 'Ć', 'ć', 'Ĉ', 'ĉ', 'Ċ', 'ċ', 'Č', 'č', 'Ď', 'ď', 'Đ', 'đ', 'Ē', 'ē', 'Ĕ', 'ĕ', 'Ė', 'ė', 'Ę', 'ę', 'Ě', 'ě', 'Ĝ', 'ĝ', 'Ğ', 'ğ', 'Ġ', 'ġ', 'Ģ', 'ģ', 'Ĥ', 'ĥ', 'Ħ', 'ħ', 'Ĩ', 'ĩ', 'Ī', 'ī', 'Ĭ', 'ĭ', 'Į', 'į', 'İ', 'ı', 'Ĳ', 'ĳ', 'Ĵ', 'ĵ', 'Ķ', 'ķ', 'Ĺ', 'ĺ', 'Ļ', 'ļ', 'Ľ', 'ľ', 'Ŀ', 'ŀ', 'Ł', 'ł', 'Ń', 'ń', 'Ņ', 'ņ', 'Ň', 'ň', 'ŉ', 'Ō', 'ō', 'Ŏ', 'ŏ', 'Ő', 'ő', 'Œ', 'œ', 'Ŕ', 'ŕ', 'Ŗ', 'ŗ', 'Ř', 'ř', 'Ś', 'ś', 'Ŝ', 'ŝ', 'Ş', 'ş', 'Š', 'š', 'Ţ', 'ţ', 'Ť', 'ť', 'Ŧ', 'ŧ', 'Ũ', 'ũ', 'Ū', 'ū', 'Ŭ', 'ŭ', 'Ů', 'ů', 'Ű', 'ű', 'Ų', 'ų', 'Ŵ', 'ŵ', 'Ŷ', 'ŷ', 'Ÿ', 'Ź', 'ź', 'Ż', 'ż', 'Ž', 'ž', 'ſ', 'ƒ', 'Ơ', 'ơ', 'Ư', 'ư', 'Ǎ', 'ǎ', 'Ǐ', 'ǐ', 'Ǒ', 'ǒ', 'Ǔ', 'ǔ', 'Ǖ', 'ǖ', 'Ǘ', 'ǘ', 'Ǚ', 'ǚ', 'Ǜ', 'ǜ', 'Ǻ', 'ǻ', 'Ǽ', 'ǽ', 'Ǿ', 'ǿ');
  $b = array('A', 'A', 'A', 'A', 'A', 'A', 'AE', 'C', 'E', 'E', 'E', 'E', 'I', 'I', 'I', 'I', 'D', 'N', 'O', 'O', 'O', 'O', 'O', 'O', 'U', 'U', 'U', 'U', 'Y', 's', 'a', 'a', 'a', 'a', 'a', 'a', 'ae', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'A', 'a', 'A', 'a', 'A', 'a', 'C', 'c', 'C', 'c', 'C', 'c', 'C', 'c', 'D', 'd', 'D', 'd', 'E', 'e', 'E', 'e', 'E', 'e', 'E', 'e', 'E', 'e', 'G', 'g', 'G', 'g', 'G', 'g', 'G', 'g', 'H', 'h', 'H', 'h', 'I', 'i', 'I', 'i', 'I', 'i', 'I', 'i', 'I', 'i', 'IJ', 'ij', 'J', 'j', 'K', 'k', 'L', 'l', 'L', 'l', 'L', 'l', 'L', 'l', 'l', 'l', 'N', 'n', 'N', 'n', 'N', 'n', 'n', 'O', 'o', 'O', 'o', 'O', 'o', 'OE', 'oe', 'R', 'r', 'R', 'r', 'R', 'r', 'S', 's', 'S', 's', 'S', 's', 'S', 's', 'T', 't', 'T', 't', 'T', 't', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'W', 'w', 'Y', 'y', 'Y', 'Z', 'z', 'Z', 'z', 'Z', 'z', 's', 'f', 'O', 'o', 'U', 'u', 'A', 'a', 'I', 'i', 'O', 'o', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'U', 'u', 'A', 'a', 'AE', 'ae', 'O', 'o');

  $quotes = array(
          "\xC2\xAB"     => '"', // « (U+00AB) in UTF-8
          "\xC2\xBB"     => '"', // » (U+00BB) in UTF-8
          "\xE2\x80\x98" => "'", // ‘ (U+2018) in UTF-8
          "\xE2\x80\x99" => "'", // ’ (U+2019) in UTF-8
          "\xE2\x80\x9A" => "'", // ‚ (U+201A) in UTF-8
          "\xE2\x80\x9B" => "'", // ‛ (U+201B) in UTF-8
          "\xE2\x80\x9C" => '"', // “ (U+201C) in UTF-8
          "\xE2\x80\x9D" => '"', // ” (U+201D) in UTF-8
          "\xE2\x80\x9E" => '"', // „ (U+201E) in UTF-8
          "\xE2\x80\x9F" => '"', // ‟ (U+201F) in UTF-8
          "\xE2\x80\xB9" => "'", // ‹ (U+2039) in UTF-8
          "\xE2\x80\xBA" => "'", // › (U+203A) in UTF-8
      );
  $str = strtr($str, $quotes);
  
  return str_replace($a, $b, $str);
}

function curl($url) {
    $_chc = array(
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXCONNECTS => 15,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 360,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.52 Safari/537.17'
    );
    $_ch = curl_init();
    curl_setopt_array($_ch, $_chc);

    curl_setopt($_ch, CURLOPT_URL, $url);
    $data = curl_exec($_ch);
    if (!$data) {
        throw new Exception (curl_error($_ch));
    }

    return $data;
}
