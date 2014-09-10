#!/usr/bin/php
<?php

/*
	Copyright 2007 Rafael de Oliveira Jannone

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
	
*/

class BasicPP {
	var $aLabels = array();
	var $aEnums = array();
	var $iUID = 0;
	var $iDepth = 0;
	var $sError;	
	
	function set_error($s) {
		if (!$this->sError)
			$this->sError = $s;
	}

	function check_label($s) {
		return preg_match('/^[a-z0-9_]+$/i', $s);
	}
	
	function get_uid() {
		$s = '$' . $this->iUID;
		++$this->iUID;
		return $s;
	}

	function fetch_enums_cb($m) {
		$i = 0;
		$sEnum = trim($m[1]);
		if ($sEnum && !$this->check_label($sEnum)) {
			$this->set_error("invalid enum label {$sEnum}");
		}		
		if ($sEnum) {
			$this->aEnums[$sEnum] = array();
		}
		$sPreName = ($sEnum) ? "{$sEnum}." : '';
		$aTerms = explode(',', $m[2]);
		foreach ($aTerms as $sTerm) {
			$aPair = explode('=', $sTerm);
			if (count($aPair) == 1)
				$aPair[] = $i;
			$sName = trim($aPair[0]);
			if (!$this->check_label($sName)) {
				$this->set_error("invalid enum label {$sName}");
			}
			$iValue = intval(trim($aPair[1]));
			$this->aLabels[$sPreName . $sName] = $iValue;
			if ($sEnum) {
				$this->aEnums[$sEnum][] = $sName;
			}
			$i = $iValue + 1;		
		}	
		return '';
	}
	
	function fetch_equs_cb($m) {
		$sEnum = trim($m[1]);
		if ($sEnum && !$this->check_label($sEnum)) {
			$this->set_error("invalid equ label {$sEnum}");
		}		
		if ($sEnum) {
			$this->aEnums[$sEnum] = array();
		}
		$sPreName = ($sEnum) ? "{$sEnum}." : '';
		$aLines = explode("\n", $m[2]);
		foreach ($aLines as $sLine) {
			$sLine = trim($sLine);
			if ($sLine === '')
				continue;
			if (!preg_match('/^([^\s]+)\s+equ\s+(.*)$/i', $sLine, $mm)) {
				$this->set_error("EQU block expects a 'LABEL equ VALUE' pair in each line");
				break;
			}
			$sLabel = $mm[1];
			$sValue = $mm[2];
			// translate hex value
			if (strlen($sValue) > 1 && strtoupper($sValue[strlen($sValue)-1]) == 'H')
				$sValue = hexdec(substr($sValue, 0, strlen($sValue)-1));
			if (!$this->check_label($sLabel)) {
				$this->set_error("invalid enum label {$sName}");
				break;
			}
			$this->aLabels[$sPreName . $sLabel] = $sValue;
			if ($sEnum) {
				$this->aEnums[$sEnum][] = $sLabel;
			}
		}	
		return '';
	}	

	function skip($sText, $p, $sReg = '\s') {
		while (preg_match('/^' . $sReg . '$/', substr($sText, $p, 1)))
			++$p;
		return $p;
	}

	function balanced($sText, &$p) {
		$i = $p;
		if (substr($sText, $p, 1) == '{') {
			++$iCnt;
			++$p;
			$iMax = strlen($sText);
			while ($p < $iMax) {
				$s = substr($sText, $p, 1);
				++$p;				
				if ($s == '{')
					++$iCnt;
				else
				if ($s == '}') {
					if (--$iCnt <= 0)
						break;					
				}
			}
		}
		return substr($sText, $i, $p - $i);
	}

	function replace_includes_cb($m) {
		if (++$this->iDepth < 20) {
			$sFile = trim($m[1]);
			$s = @file_get_contents($sFile);
			if ($s === FALSE) {
				$this->set_error("Can't include file '{$sFile}'");
			} else {
				return $this->run_replace_includes($s);
			}
		} else {
			$this->set_error("Recursion is too deep");			
		}
		--$this->iDepth;
	}

	function replace_ifs($sText) {
		$i = stripos($sText, '$if');
		while ($i !== FALSE) {
			$iExp = $i + 3; // skip $if
			$p = $this->skip($sText, $iExp, '[^{]');
			$sExpr = trim(substr($sText, $iExp, $p - $iExp));
			$sBlock = $this->balanced($sText, $p);
			if ($sBlock[0] != '{') {
				$this->set_error("IF block must start with '{' and end with '}'");
			}
			$sBlock = substr($sBlock, 1, strlen($sBlock)-2);
			
			// set a label for the block
			$sBlockLabel = $this->get_uid();
		
			// set a label for the ending
			$sEndIfLabel = $this->get_uid();
			
			// recursive call to allow nested ifs
			$sBlock = trim($this->replace_ifs($sBlock));
									
			// handle $else statement
			$sTemp = substr($sText, $p); // workaround, preg_match anchor with offset not working as expected
			if (preg_match('/^\s*\$else\s*\{/is', $sTemp, $m)) {
				$sElseLabel = $this->get_uid();
				
				// an else must be followed by another block { ... }
				$p += strlen($m[0])-1;
				$sElseBlock = $this->balanced($sText, $p);
				if ($sElseBlock[0] != '{') {
					$this->set_error("ELSE block must start with '{' and end with '}'");
				}
				$sElseBlock = substr($sElseBlock, 1, strlen($sElseBlock)-2);
				$sElseBlock = trim($this->replace_ifs($sElseBlock));

				$sBlock .= "\nGOTO {$sEndIfLabel}\n";

				$sExpanded = "IF {$sExpr} THEN {$sBlockLabel} ELSE {$sElseLabel}\n{$sBlockLabel}:" .
					"{$sBlock}\n{$sElseLabel}:{$sElseBlock}\n{$sEndIfLabel}:";
			} else {
				$sExpanded = "IF {$sExpr} THEN {$sBlockLabel} ELSE {$sEndIfLabel}\n{$sBlockLabel}:" .
					"{$sBlock}\n{$sEndIfLabel}:";
			}
			$sText = substr($sText, 0, $i) . $sExpanded . substr($sText, $p);
			$i = stripos($sText, '$if', $i + strlen($sExpanded));
		}
		return $sText;
	}

	function replace_dims_cb($m) {	
		$sBlock = $m[3];
		$aTerms = explode(',', $sBlock);
		$aLines = array();
		
		if (!$this->check_label($m[1]))
			$this->set_error("Invalid label {$m[1]}");
		
		foreach ($aTerms as $sTerm) {
			$sTerm = trim($sTerm);
			if ($sTerm === '')
				continue;
			if (!preg_match('/^(.*)\s+as\s+(.*)$/iU', trim($sTerm), $mm)) {
				$this->set_error("DIM member must be a tuple in the form 'LABEL as VAR'");
			}
			$sLabel = $mm[1];
			$sVar = $mm[2];
			if (!$this->check_label($sLabel))
				$this->set_error("Invalid label {$sLabel}");
			$sLabel = $m[1] . '.' . $sLabel;
			$this->aLabels[$sLabel] = $sVar;
			$aLines[] = "DIM {$sVar}({$m[2]})";
		}
		return implode("\n", $aLines);
	}

	function replace_ongosubs_cb($m) {
		$sBlock = $m[2];
		$sExpr = $m[1];

		if (!preg_match('/^(.*)\s+as\s+(.*)$/iU', trim($sBlock), $mm)) {
			$this->set_error("ONGOSUB block must be a tuple in the form 'ENUM as PREFIX'");
		}

		if (!$this->check_label($mm[2]))
			$this->set_error("Invalid prefix {$mm[2]}");

		if (!isset($this->aEnums[$mm[1]]))
			$this->set_error("Invalid enum {$mm[1]}");
			
		$aLabels = array();
		
		foreach ($this->aEnums[$mm[1]] as $sLabel) {
			$aLabels[] = '$'. $mm[2] . $sLabel;			
		}
		return "on {$sExpr} gosub " . implode(',', $aLabels);
	}

	function replace_references_cb($m) {
		$s = $m[1];
		if ($s == '$')
			return '$';
		if (strtolower($s) == 'eval')
			return '$eval'; // save it for later
		if (!isset($this->aLabels[$s])) {
			$sTip = '';
			if ($s == 'else') {
				$sTip = ' (did you forget to put a $ before IF?)';
			}
			$this->set_error("Undefined label {$s}{$sTip}");
		}
		return $this->aLabels[$m[1]];
	}

	function replace_evals_cb($m) {
		$sFlags = strtolower(trim($m[1]));
		$sExp = $m[2];
		$i = eval("return {$sExp};");
		$aFlags = array_map('trim', explode(" ", $sFlags));
		foreach ($aFlags as $sFlag) {		
			switch ($sFlag) {
				case 'hex':
					$i = '&h' . dechex($i);
					break;
				case 'high':
					$i = floor($i/256);
					break;
				case 'low':
					$i = $i % 256;
					break;
			}
		}
		return $i;
	}

	function run_replace_includes($sText) {
		return preg_replace_callback('/\$include\s*{([^\}]*)\}/is', array($this, 'replace_includes_cb'), $sText);	
	}

	function start($sText) {
		$sText = trim($sText);

		$sText = $this->run_replace_includes($sText);

		$sText = preg_replace_callback('/\$equ\s+([a-z0-9_]*)\s*\{([^\}]*)\}/is', array($this, 'fetch_equs_cb'), $sText);

		$sText = preg_replace_callback('/\$enum\s+([a-z0-9_]*)\s*\{([^\}]*)\}/is', array($this, 'fetch_enums_cb'), $sText);
		
		$sText = preg_replace_callback('/\$dim\s+([a-z0-9_]+)\(([^\)]+)\)\s*\{([^\}]*)\}/is', 
			array($this, 'replace_dims_cb'), $sText);

		$sText = preg_replace_callback('/\$ongosub\s+([^\{]+)\s*\{([^\}]*)\}/is', 
			array($this, 'replace_ongosubs_cb'), $sText);
		
		$sText = $this->replace_ifs($sText);
		
		// [debug] return $sText;
		
		$aLines = explode("\n", $sText);

		$iLine = 0;
		$iLast = -1;
		$aNewLines = array();
		foreach ($aLines as $sLine) {
			$sLine = trim($sLine);
			if ($sLine === '')
				continue;
			$a = array();
			while (preg_match('/^\$([a-z0-9_]+)\s*:\s*/i', $sLine, $m)) {
				$sLine = trim(substr($sLine, strlen($m[0])));
				$a[] = $m[1];
			}
			if (preg_match('/^([0-9]+) +/', $sLine, $m)) {
				$iUserLine = intval($m[1]);
				if ($iUserLine <= $iLast) {
					$this->set_error("user line {$iUserLine} redefining automatic line\n");
				}
				$aNewLines[] = $sLine;
				$iLine = $iUserLine;
			} else {
				if ($sLine == '')
					$sLine = 'REM';
				$sLine = $iLine . " " . $sLine;
				$aNewLines[] = $sLine;
				$iLast = $iLine;
			}
			foreach ($a as $sLabel) {
				$this->aLabels[$sLabel] = $iLine;
			}
			++$iLine;
		}

		$sText = implode("\n", $aNewLines);

		$sText = preg_replace_callback('/\$(\$|[a-z0-9_\.]+)/is', array($this, 'replace_references_cb'), $sText);
		
		$sText = preg_replace_callback('/\$eval(\s+[a-z ]+)?\s*\{([^\}]*)\}/is', array($this, 'replace_evals_cb'), $sText);

		return $sText;
	}	
}

if ($argv[1]) {
	// use it stand-alone
	$sText = file_get_contents($argv[1]);
	$basic_pp = new BasicPP();
	$s = $basic_pp->start($sText);
	if ($basic_pp->sError) {
		file_put_contents("php://stderr", $basic_pp->sError . "\n");
		exit(1);
	}
	echo $s;
	exit(0);
}

?>
