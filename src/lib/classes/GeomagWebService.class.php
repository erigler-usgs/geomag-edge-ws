<?php

include_once $LIB_DIR . '/classes/WebService.class.php';
include_once $LIB_DIR . '/classes/GeomagQuery.class.php';
include_once $LIB_DIR . '/classes/Iaga2002OutputFormat.class.php';
include_once $LIB_DIR . '/classes/JsonOutputFormat.class.php';


class GeomagWebService extends WebService {

  const VERSION = '0.1.3';

  public $waveserver;
  public $metadata;

  /**
   * Construct a new GeomagWebService.
   *
   * @param $waveserver {WaveServer}
   *    Waveserver object used to fetch data.
   * @param $metadata {Array<String, Array>}
   *        Associative array of metadata, keyed by upper case observatory id.
   */
  public function __construct($waveserver, $metadata) {
    parent::__construct(self::VERSION);
    $this->waveserver = $waveserver;
    $this->metadata = $metadata;
  }

  /**
   * Run web service.
   */
  public function run() {
    try {
      $query = $this->parseQuery($_GET);
    } catch (Exception $e) {
      $this->error(self::BAD_REQUEST, $e->getMessage());
    }

    try {
      $data = $this->getData($query);
      if ($query->format === 'iaga2002') {
        $output = new Iaga2002OutputFormat();
      } else {
        $output = new JsonOutputFormat();
      }
      $output->output($data, $query, $this->metadata);
    } catch (Exception $e) {
      $this->error(self::SERVER_ERROR, $e->getMessage());
    }
  }

  /**
   * Get requested data.
   *
   * @param $query {GeomagQuery}
   *        web service query.
   * @return {Array<String, WaveServerResponse}
   *         associative array of data.
   *         keys are requested elements.
   */
  protected function getData($query) {
    $data = [];

    $endtime = $query->endtime;
    $sampling_period = $query->sampling_period;
    $starttime = $query->starttime;
    $station = $query->id;
    $type = $query->type;

    // build times array
    $times = array();
    $len = ($endtime - $starttime) / $sampling_period;
    for ($i = 0; $i <= $len; $i++) {
      $times[] = $starttime + $i * $sampling_period;
    }
    $data['times'] = $times;

    foreach ($query->elements as $element) {
      $sncl = $this->getSNCL(
          $station,
          $element,
          $query->sampling_period,
          $query->type);
      $response = $this->waveserver->get(
          $starttime,
          $endtime,
          $sncl['station'],
          $sncl['network'],
          $sncl['channel'],
          $sncl['location']);

      // build values array
      $values = $response->getDataArray($starttime, $endtime);
      if (!is_array($values)) {
        // empty channel
        $values = array_fill(0, count($times), null);
      } else {
        $values = array_map(function ($val) {
          if ($val === null) {
            return null;
          }
          return $val / 1000;
        }, $values);
      }

      $data[$element] = array(
        'sncl' => $sncl,
        'element' => $element,
        'response' => $response,
        'values' => $values
      );
    }

    return $data;
  }

  /**
   * Translate requested elements to EDGE SNCL codes.
   *
   * @param $station {String}
   *        observatory.
   * @param $element {Array<String>}
   *        requested elements.
   * @param $sampling_period {Number}
   *        1 for seconds, 60 for minutes.
   * @param $type {String}
   *        'variation', 'adjusted', 'quasi-definitive', or 'definitive'.
   */
  protected function getSNCL($station, $element, $sampling_period, $type) {
    $network = 'NT';
    $station = $station;

    $channel = '';
    if ($sampling_period === 60) {
      $prefix = 'M';
    } else if ($sampling_period === 1) {
      $prefix = 'S';
    }

    $element = strtoupper($element);
    switch ($element) {
      case 'D':
      case 'E':
      case 'H':
      case 'X':
      case 'Y':
      case 'Z':
        $channel = $prefix . 'V' . $element;
        break;
      case 'F':
      case 'G':
        $channel = $prefix . 'S' . $element;
        break;
      case 'SQ':
      case 'SV':
        $channel = $prefix . $element;
        break;
      case 'DIST':
        $channel = $prefix . 'DT';
        break;
      case 'DST':
        $channel = $prefix . 'GD';
        break;
      default:
        if (preg_match('/^[A-Z][A-Z0-9]{2}$/', $element)) {
          // seems like an edge channel code
          $channel = $element;
        } else {
          $this->error(self::BAD_REQUEST, 'Unknown element "' . $element . '"');
        }
        break;
    }

    if ($type === 'variation') {
      $location = 'R0';
    } else if ($type === 'adjusted') {
      $location = 'A0';
    } else if ($type === 'quasi-definitive') {
      $location = 'Q0';
    } else if ($type === 'definitive') {
      $location = 'D0';
    } else {
      $location = $type;
    }

    return array(
      'station' => $station,
      'network' => $network,
      'channel' => $channel,
      'location' => $location
    );
  }

  protected function parseQuery($params) {
    $query = new GeomagQuery();

    foreach ($params as $name => $value) {
      if ($value === '') {
        // treat empty values as missing parameters
        continue;
      }
      if ($name === 'id') {
        $query->id = $this->validateEnumerated($name, strtoupper($value),
            array_keys($this->metadata));
      } else if ($name === 'starttime') {
        $query->starttime = $this->validateTime($name, $value);
      } else if ($name === 'endtime') {
        $query->endtime = $this->validateTime($name, $value);
      } else if ($name === 'elements') {
        if (!is_array($value)) {
          $value = explode(',', $value);
        }
        $value = array_map(function ($value) {
          return strtoupper($value);
        }, $value);
        $query->elements = $value;
      } else if ($name === 'sampling_period') {
        $query->sampling_period = intval(
            $this->validateEnumerated($name, $value,
                // valid sampling periods
                // 1 = second
                // 60 = minute
                // 3600 = hour
                array(1, 60, 3600)));
      } else if ($name === 'type') {
        if (preg_match('/^[A-Z0-9]{2}$/', $value)) {
          // edge location code
          $query->type = $value;
        } else {
          $query->type = $this->validateEnumerated($name, $value,
              array('variation', 'adjusted', 'quasi-definitive', 'definitive'));
        }
      } else if ($name === 'format') {
        $query->format = $this->validateEnumerated($name, strtolower($value),
              array('iaga2002', 'json'));
      } else {
        $this->error(self::BAD_REQUEST, 'Unknown parameter "' . $name . '"');
      }
    }

    // set defaults
    if ($query->id === null) {
      throw new Exception('"id" is a required parameter');
    }
    if ($query->starttime === null) {
      $query->starttime = strtotime(gmdate('Y-m-d'));
    }
    if ($query->endtime === null) {
      // default to starttime + 24 hours
      $query->endtime = $query->starttime + (24 * 60 * 60 - 1);
    }
    if ($query->elements === null) {
      // default when not specified
      $query->elements = array('X', 'Y', 'Z', 'F');
    }

    return $query;
  }

}
