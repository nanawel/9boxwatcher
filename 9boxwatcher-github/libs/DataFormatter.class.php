<?php
/**
 * Handle data formatting for console display.
 */
class DataFormatter {
	
	const OUTPUT_HUMAN = 1;
	const OUTPUT_SCRIPT = 2;
	const OUTPUT_CSV = 3;
	
	/**
	 * @see OUTPUT_* constants above
	 * @var int
	 */
	protected $_style = null;
	
	/**
	 * @var string
	 */
	public $csvSeparator = ';';
	/**
	 * @var string
	 */
	public $csvEnclosure = '"';
	/**
	 * @var string
	 */
	public $newLine = "\n";
	
	
	/**
	 * 
	 * @see OUTPUT_* constants above
	 * @param int $style
	 */
	public function __construct($style = self::OUTPUT_HUMAN) {
		$this->_style = $style;
	}
	
	/**
	 * Format data for output.
	 * 
	 * @param mixed $data
	 * @param string $label
	 * @return string
	 */
	public function format($data, $label = null) {
		$output = $label ? "[ $label ]" . $this->newLine : '';
		if (is_array($data)) {
			switch($this->_style) {
				case self::OUTPUT_HUMAN:
					$output .= self::drawTextTable($data);
					break;
				case self::OUTPUT_SCRIPT:
					$output .= self::tableToCsv($data, '', $this->csvSeparator, $this->newLine);
					break;
				case self::OUTPUT_CSV:
					$output .= self::tableToCsv($data, '"', $this->csvSeparator, $this->newLine);
					break;
			}
		}
		else {
			$output .= $data;
		}
		return $output;
	}
	
	/**
	 * Multidimensonalize (oO) an array.
	 * 
	 * @param array $arr
	 * @param string $keyHeader
	 * @param string $valueHeader
	 * @return array
	 */
	protected static function _multidimensionalize(array $arr, $keyHeader = 'Label', $valueHeader = 'Value') {
		// Check unidimensional array and rewrite it if needed
		$isMultidimensional = true;
		foreach($arr as $row) {
			if (!is_array($row)) {
				$isMultidimensional = false;
				break;
			}
		}
		if (!$isMultidimensional) {
			$tmp = array();
			foreach($arr as $label => $value) {
				$tmp[] = array(
					$keyHeader 		=> $label,
					$valueHeader	=> $value,
				);
			}
			$arr = $tmp;
		}
		return $arr;
	}
	
	/**
	 * Returns a human-readable string representation of an array.
	 * Like this:
	 * +-----------------------+------------+
	 * | Label                 | Value      |
	 * +-----------------------+------------+
	 * | Débit flux descendant | 15999 Kbps |
	 * | Débit flux montant    | 1021 Kbps  |
	 * +-----------------------+------------+
	 * 
	 * @param array $table
	 * @param boolean $cropLongHeaders
	 * @return string
	 */
	public static function drawTextTable(array $table, $cropLongHeaders = false, $newLine = "\n") {
		$table = self::_multidimensionalize($table);
		
		// Work out max lengths of each cell
		foreach ($table AS $rowId => $row) {
			foreach ($row AS $key => $cell) {
				$cell_length = mb_strlen($cell, 'utf8');
				if (!isset($cellLengths[$key]) || $cell_length > $cellLengths[$key]) {
					$cellLengths[$key] = $cell_length;
				}
			}
		}

		// Build header bar
		$bar = '+';
		$header = '|';
		$i=0;
		foreach ($cellLengths AS $fieldname => $length) {
			$i++;

			if (mb_strlen($fieldname, 'utf8') > $length && $cropLongHeaders) {
				$fieldname = mb_substr($fieldname, 0, $length-1, 'utf8');
			}
			else {
				$cellLengths[$fieldname] = $length = max($length, mb_strlen($fieldname, 'utf8'));
			}
			$bar .= self::mb_str_pad('', $length+2, '-', STR_PAD_RIGHT, 'utf8')."+";
			$header .= ' '.self::mb_str_pad($fieldname, $length, ' ', STR_PAD_RIGHT, 'utf8') . " |";
		}

		$output = '';
		$output .= $bar.$newLine;
		$output .= $header.$newLine;
		$output .= $bar.$newLine;

		// Draw rows
		foreach ($table AS $row) {
			$output .= "|";
			foreach ($row AS $key => $cell) {
				$output .= ' '.self::mb_str_pad($cell, $cellLengths[$key], ' ', STR_PAD_RIGHT, 'utf8') . " |";
			}
			$output .= $newLine;
		}
		$output .= $bar;

		return $output;
	}
	
	/**
	 * Return a CSV formatted string of the array.
	 * 
	 * @param array $table
	 * @param string $enclosure
	 * @param string $separator
	 * @param string $newLine
	 * @return string
	 */
	public static function tableToCsv(array $table, $enclosure = '"', $separator = ';', $newLine = "\n") {
		$table = self::_multidimensionalize($table);
		
		$headers = array();
		$body = array();
		$isFirstRow = true;
		foreach ($table AS $rowId => $row) {
			foreach ($row AS $key => $cell) {
				if ($isFirstRow) {
					$headers[] = $enclosure . $key . $enclosure;
				}
				if (!isset($body[$rowId])) {
					$body[$rowId] = array();
				}
				$body[$rowId][] = $enclosure . $cell . $enclosure;
			}
			$body[$rowId] = join($separator, $body[$rowId]);
			$isFirstRow = false;
		}
		return join($separator, $headers) . $newLine . join($newLine, $body);
	}
	
	/**
	 * str_pad() replacement for multibyte strings.
	 * 
	 * @param string $input
	 * @param int $pad_length
	 * @param string $pad_string
	 * @param int $pad_type STR_PAD_BOTH is NOT handled here
	 * @param string $encoding
	 * @return string
	 */
	public static function mb_str_pad($input, $pad_length, $pad_string, $pad_type = STR_PAD_RIGHT, $encoding) {
		$length = mb_strlen($input, $encoding);
		for($i = $length; $i < $pad_length; $i++) {
			if ($pad_type == STR_PAD_LEFT) {
				$input = $pad_string . $input;
			}
			else {
				$input .= $pad_string;
			}
		}
		return $input;
	}
}
?>
