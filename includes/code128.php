<?php
/**
 *  code128.php
 *
 *  A lightweight Code‑128 barcode writer for FPDF.
 *  Source adapted from the public‑domain class by Jean‑Baptiste Leroux,
 *  with small tweaks for PHP 8+ compatibility.
 *
 *  Usage:
 *     require_once 'fpdf.php';
 *     require_once 'code128.php';
 *     $pdf = new PDF_Code128();
 *     $pdf->AddPage();
 *     $pdf->Code128($x, $y, $barcodeString, $width = 80, $height = 20);
 */

require_once 'fpdf.php';

class PDF_Code128 extends FPDF
{
    protected $T128, $ABCset, $Aset, $Bset, $Cset, $SetFrom, $SetTo, $JStart, $JSwap;

    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        parent::__construct($orientation,$unit,$size);

        // Table of character sets
        $this->Aset = array_fill(0, 128, 0);
        $this->Bset = array_fill(0, 128, 0);
        for ($i = 0; $i <= 95; $i++) { $this->Aset[$i] = chr($i);  }
        for ($i = 32; $i <= 127; $i++) { $this->Bset[$i] = chr($i); }

        $this->Cset = array();
        for ($i = 0; $i <= 99; $i++) { $this->Cset[] = sprintf('%02d', $i); }

        // Convert table : Set/Get array
        $this->SetFrom = array(
            'A' => implode('', $this->Aset),
            'B' => implode('', $this->Bset),
            'C' => implode('', $this->Cset)
        );
        $this->SetTo   = array(
            'A' => implode('', array_keys($this->Aset)),
            'B' => implode('', array_keys($this->Bset)),
            'C' => implode('', $this->Cset)
        );

        // Start codes, swap codes
        $this->JStart = array('A'=>103, 'B'=>104, 'C'=>105);
        $this->JSwap  = array('A'=>101, 'B'=>100, 'C'=>99);

        // Encoded patterns (bars & spaces for values 0–106)
        $this->T128 = array(
            /* 0 */ '11011001100','11001101100','11001100110','10010011000','10010001100',
            /* 5 */ '10001001100','10011001000','10011000100','10001100100','11001001000',
            /* 10*/ '11001000100','11000100100','10110011100','10011011100','10011001110',
            /* 15*/ '10111001100','10011101100','10011100110','11001110010','11001011100',
            /* 20*/ '11001001110','11011100100','11001110100','11101101110','11101001100',
            /* 25*/ '11100101100','11100100110','11101100100','11100110100','11100110010',
            /* 30*/ '11011011000','11011000110','11000110110','10100011000','10001011000',
            /* 35*/ '10001000110','10110001000','10001101000','10001100010','11010001000',
            /* 40*/ '11000101000','11000100010','10110111000','10110001110','10001101110',
            /* 45*/ '10111011000','10111000110','10001110110','11101110110','11010001110',
            /* 50*/ '11000101110','11011101000','11011100010','11011101110','11101011000',
            /* 55*/ '11101000110','11100010110','11101101000','11101100010','11100011010',
            /* 60*/ '11101111010','11001000010','11110001010','10100110000','10100001100',
            /* 65*/ '10010110000','10010000110','10000101100','10000100110','10110010000',
            /* 70*/ '10110000100','10011010000','10011000010','10000110100','10000110010',
            /* 75*/ '11000010010','11001010000','11110111010','11000010100','10001111010',
            /* 80*/ '10100111100','10010111100','10010011110','10111100100','10011110100',
            /* 85*/ '10011110010','11110100100','11110010100','11110010010','11011011110',
            /* 90*/ '11011110110','11110110110','10101111000','10100011110','10001011110',
            /* 95*/ '10111101000','10111100010','11110101000','11110100010','10111011110',
            /*100*/ '10111101110','11101011110','11110101110','11010000100','11010010000',
            /*105*/ '11010011100','1100011101011'
        );
    }

    function Code128($x, $y, $code, $w, $h)
    {
        // Choose set automatically
        $Aguid = $Bguid = $Cguid = '';
        for ($i = 0; $i < strlen($code); $i++) {
            $c = ord($code[$i]);
            $Aguid .= ($c >= 32 && $c <= 95) ? 'O' : 'N';
            $Bguid .= ($c >= 32 && $c <= 127) ? 'O' : 'N';
            $Cguid .= ($i+1 < strlen($code) && is_numeric($code[$i]) && is_numeric($code[$i+1]))
                    ? 'O' : 'N';
        }

        $SminiC = 'OOOO';
        $IminiC = strpos($Cguid, $SminiC);
        $set = 'B';
        if ($IminiC !== false) $set = 'C';
        elseif (strpos($Aguid, 'N') === false) $set = 'A';
        elseif (strpos($Bguid, 'N') === false) $set = 'B';

        // Encode
        $code_extended = '';
        $currentSet = '';
        while (strlen($code) > 0) {
            if ($set == 'C') {
                $len = 4;
                $part = substr($code, 0, $len);
                $code = substr($code, $len);
                $code_extended .= chr($this->JStart['C']).$part;
            } else {
                $part = substr($code, 0, 1);
                $code = substr($code, 1);
                if ($currentSet != $set) {
                    $code_extended .= chr($this->JStart[$set]);
                    $currentSet = $set;
                }
                $code_extended .= chr(array_search($part, $this->SetFrom[$set]));
            }
        }

        // Checksum
        $checksum = ord($code_extended[0]);
        for ($i = 1; $i < strlen($code_extended); $i++) {
            $checksum += (ord($code_extended[$i]) * $i);
        }
        $checksum %= 103;

        // Add checksum and stop
        $code_extended .= chr($checksum).chr(106);

        // Draw bars
        $barString = '';
        for ($i = 0; $i < strlen($code_extended); $i++) {
            $bars = $this->T128[ord($code_extended[$i])];
            $barString .= $bars;
        }

        $barWidth = $w / strlen($barString);
        $this->SetFillColor(0);
        for ($i = 0; $i < strlen($barString); $i++) {
            if ($barString[$i] == '1') {
                $this->Rect($x + $i * $barWidth, $y, $barWidth, $h, 'F');
            }
        }
    }
}
?>
