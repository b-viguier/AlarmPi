<?php

namespace bviguier\AlarmPi\Hardware;

use PiPHP\GPIO\Pin\OutputPinInterface;
use PiPHP\GPIO\Pin\PinInterface;

// When the display powers up, it is configured as follows:
//
// 1. Display clear
// 2. Function set:
//    DL = 1; 8-bit interface data
//    N = 0; 1-line display
//    F = 0; 5x8 dot character font
// 3. Display on/off control:
//    D = 0; Display off
//    C = 0; Cursor off
//    B = 0; Blinking off
// 4. Entry mode set:
//    I/D = 1; Increment by 1
//    S = 0; No shift
//
// Note, however, that resetting the Arduino doesn't reset the LCD, so we
// can't assume that its in that state when a sketch starts (and the
// LiquidCrystal constructor is called).


class LiquidCrystal
{
    const LCD_CLEARDISPLAY = 0x01;
    const LCD_RETURNHOME = 0x02;
    const LCD_ENTRYMODESET = 0x04;
    const LCD_DISPLAYCONTROL = 0x08;
    const LCD_CURSORSHIFT = 0x10;
    const LCD_FUNCTIONSET = 0x20;
    const LCD_SETCGRAMADDR = 0x40;
    const LCD_SETDDRAMADDR = 0x80;

// flags for display entry mode
    const LCD_ENTRYRIGHT = 0x00;
    const LCD_ENTRYLEFT = 0x02;
    const LCD_ENTRYSHIFTINCREMENT = 0x01;
    const LCD_ENTRYSHIFTDECREMENT = 0x00;

// flags for display on/off control
    const LCD_DISPLAYON = 0x04;
    const LCD_DISPLAYOFF = 0x00;
    const LCD_CURSORON = 0x02;
    const LCD_CURSOROFF = 0x00;
    const LCD_BLINKON = 0x01;
    const LCD_BLINKOFF = 0x00;

// flags for display/cursor shift
    const LCD_DISPLAYMOVE = 0x08;
    const LCD_CURSORMOVE = 0x00;
    const LCD_MOVERIGHT = 0x04;
    const LCD_MOVELEFT = 0x00;

// flags for function set
    const LCD_8BITMODE = 0x10;
    const LCD_4BITMODE = 0x00;
    const LCD_2LINE = 0x08;
    const LCD_1LINE = 0x00;
    const LCD_5x10DOTS = 0x04;
    const LCD_5x8DOTS = 0x00;


    /** @var  OutputPinInterface */
    private $_rs_pin; // LOW: command.  HIGH: character.
    /** @var  OutputPinInterface */
    private $_rw_pin; // LOW: write to LCD.  HIGH: read from LCD.
    /** @var  OutputPinInterface */
    private $_enable_pin; // activated by a HIGH pulse.
    /** @var  OutputPinInterface[] */
    private $_data_pins = [];

    private $_displayfunction;
    private $_displaycontrol;
    private $_displaymode;

    private $_initialized;

    private $_numlines;
    private $_row_offsets = [];

    public function __construct(OutputPinInterface $rs, OutputPinInterface $enable,
                                OutputPinInterface $d0, OutputPinInterface $d1, OutputPinInterface $d2, OutputPinInterface $d3)
    {
        $this->init(1, $rs, null, $enable, $d0, $d1, $d2, $d3, null, null, null, null);
    }

    public function init(int $fourbitmode, OutputPinInterface $rs, OutputPinInterface $rw = null, OutputPinInterface $enable,
                         OutputPinInterface $d0, OutputPinInterface $d1, OutputPinInterface $d2, OutputPinInterface $d3,
                         OutputPinInterface $d4 = null, OutputPinInterface $d5 = null, OutputPinInterface $d6 = null, OutputPinInterface $d7 = null)
    {
        $this->_rs_pin = $rs;
        $this->_rw_pin = $rw;
        $this->_enable_pin = $enable;

        $this->_data_pins[0] = $d0;
        $this->_data_pins[1] = $d1;
        $this->_data_pins[2] = $d2;
        $this->_data_pins[3] = $d3;
        $this->_data_pins[4] = $d4;
        $this->_data_pins[5] = $d5;
        $this->_data_pins[6] = $d6;
        $this->_data_pins[7] = $d7;

        if ($fourbitmode)
            $this->_displayfunction = self::LCD_4BITMODE | self::LCD_1LINE | self::LCD_5x8DOTS;
        else
            $this->_displayfunction = self::LCD_8BITMODE | self::LCD_1LINE | self::LCD_5x8DOTS;

        $this->begin(16, 1);
    }

    public function begin(int $cols, int $lines, int $dotsize = self::LCD_5x8DOTS)
    {
        if ($lines > 1) {
            $this->_displayfunction |= self::LCD_2LINE;
        }
        $this->_numlines = $lines;

        $this->setRowOffsets(0x00, 0x40, 0x00 + $cols, 0x40 + $cols);

        // for some 1 line displays you can select a 10 pixel high font
        if (($dotsize != self::LCD_5x8DOTS) && ($lines == 1)) {
            $this->_displayfunction |= self::LCD_5x10DOTS;
        }

        /*
        pinMode($this->_rs_pin, OUTPUT);
        // we can save 1 pin by not using RW. Indicate by passing 255 instead of pin#
        if ($this->_rw_pin != 255) {
            pinMode($this->_rw_pin, OUTPUT);
        }
        pinMode($this->_enable_pin, OUTPUT);

        // Do these once, instead of every time a character is drawn for speed reasons.
        for ($i = 0; $i < (($this->_displayfunction & self::LCD_8BITMODE) ? 8 : 4);
             ++$i) {
            pinMode($this->_data_pins[$i], OUTPUT);
        }
        */

        // SEE PAGE 45/46 FOR INITIALIZATION SPECIFICATION!
        // according to datasheet, we need at least 40ms after power rises above 2.7V
        // before sending commands. Arduino can turn on way before 4.5V so we'll wait 50
        usleep(50000);
        // Now we pull both RS and R/W low to begin commands
        $this->_rs_pin->setValue(PinInterface::VALUE_LOW);
        $this->_enable_pin->setValue(PinInterface::VALUE_LOW);
        if ($this->_rw_pin !== null) {
            $this->_rw_pin->setValue(PinInterface::VALUE_LOW);
        }

        //put the LCD into 4 bit or 8 bit mode
        if (!($this->_displayfunction & self::LCD_8BITMODE)) {
            // this is according to the hitachi HD44780 datasheet
            // figure 24, pg 46

            // we start in 8bit mode, try to set 4 bit mode
            $this->write4bits(0x03);
            usleep(4500); // wait min 4.1ms

            // second try
            $this->write4bits(0x03);
            usleep(4500); // wait min 4.1ms

            // third go!
            $this->write4bits(0x03);
            usleep(150);

            // finally, set to 4-bit interface
            $this->write4bits(0x02);
        } else {
            // this is according to the hitachi HD44780 datasheet
            // page 45 figure 23

            // Send function set command sequence
            $this->command(self::LCD_FUNCTIONSET | $this->_displayfunction);
            usleep(4500);  // wait more than 4.1ms

            // second try
            $this->command(self::LCD_FUNCTIONSET | $this->_displayfunction);
            usleep(150);

            // third go
            $this->command(self::LCD_FUNCTIONSET | $this->_displayfunction);
        }

        // finally, set # lines, font size, etc.
        $this->command(self::LCD_FUNCTIONSET | $this->_displayfunction);

        // turn the display on with no cursor or blinking default
        $this->_displaycontrol = self::LCD_DISPLAYON | self::LCD_CURSOROFF | self::LCD_BLINKOFF;
        $this->display();

        // clear it off
        $this->clear();

        // Initialize to default text direction (for romance languages)
        $this->_displaymode = self::LCD_ENTRYLEFT | self::LCD_ENTRYSHIFTDECREMENT;
        // set the entry mode
        $this->command(self::LCD_ENTRYMODESET | $this->_displaymode);

    }

    public function setRowOffsets(int $row0, int $row1, int $row2, int $row3)
    {
        $this->_row_offsets[0] = $row0;
        $this->_row_offsets[1] = $row1;
        $this->_row_offsets[2] = $row2;
        $this->_row_offsets[3] = $row3;
    }

    /********** high level commands, for the user! */
    public function clear()
    {
        $this->command(self::LCD_CLEARDISPLAY);  // clear display, set cursor position to zero
        usleep(2000);  // this command takes a long time!
    }

    public function home()
    {
        $this->command(self::LCD_RETURNHOME);  // set cursor position to zero
        usleep(2000);  // this command takes a long time!
    }

    public function setCursor(int $col, int $row)
    {
        $max_lines = count($this->_row_offsets);
        if ($row >= $max_lines) {
            $row = $max_lines - 1;    // we count rows starting w/0
        }
        if ($row >= $this->_numlines) {
            $row = $this->_numlines - 1;    // we count rows starting w/0
        }

        $this->command(self::LCD_SETDDRAMADDR | ($col + $this->_row_offsets[$row]));
    }

// Turn the display on/off (quickly)
    public function noDisplay()
    {
        $this->_displaycontrol &= ~self::LCD_DISPLAYON;
        $this->command(self::LCD_DISPLAYCONTROL | $this->_displaycontrol);
    }

    public function display()
    {
        $this->_displaycontrol |= self::LCD_DISPLAYON;
        $this->command(self::LCD_DISPLAYCONTROL | $this->_displaycontrol);
    }

// Turns the underline cursor on/off
    public function noCursor()
    {
        $this->_displaycontrol &= ~self::LCD_CURSORON;
        $this->command(self::LCD_DISPLAYCONTROL | $this->_displaycontrol);
    }

    public function cursor()
    {
        $this->_displaycontrol |= self::LCD_CURSORON;
        $this->command(self::LCD_DISPLAYCONTROL | $this->_displaycontrol);
    }

// Turn on and off the blinking cursor
    public function noBlink()
    {
        $this->_displaycontrol &= ~self::LCD_BLINKON;
        $this->command(self::LCD_DISPLAYCONTROL | $this->_displaycontrol);
    }

    public function blink()
    {
        $this->_displaycontrol |= self::LCD_BLINKON;
        $this->command(self::LCD_DISPLAYCONTROL | $this->_displaycontrol);
    }

// These commands scroll the display without changing the RAM
    public function scrollDisplayLeft()
    {
        $this->command(self::LCD_CURSORSHIFT | self::LCD_DISPLAYMOVE | self::LCD_MOVELEFT);
    }

    public function scrollDisplayRight()
    {
        $this->command(self::LCD_CURSORSHIFT | self::LCD_DISPLAYMOVE | self::LCD_MOVERIGHT);
    }

// This is for text that flows Left to Right
    public function leftToRight()
    {
        $this->_displaymode |= self::LCD_ENTRYLEFT;
        $this->command(self::LCD_ENTRYMODESET | $this->_displaymode);
    }

// This is for text that flows Right to Left
    public function rightToLeft()
    {
        $this->_displaymode &= ~self::LCD_ENTRYLEFT;
        $this->command(self::LCD_ENTRYMODESET | $this->_displaymode);
    }

// This will 'right justify' text from the cursor
    public function autoscroll()
    {
        $this->_displaymode |= self::LCD_ENTRYSHIFTINCREMENT;
        $this->command(self::LCD_ENTRYMODESET | $this->_displaymode);
    }

// This will 'left justify' text from the cursor
    public function noAutoscroll()
    {
        $this->_displaymode &= ~self::LCD_ENTRYSHIFTINCREMENT;
        $this->command(self::LCD_ENTRYMODESET | $this->_displaymode);
    }

// Allows us to fill the first 8 CGRAM locations
// with custom characters
    public function createChar(int $location, array $charmap)
    {
        $location &= 0x7; // we only have 8 locations 0-7
        $this->command(self::LCD_SETCGRAMADDR | ($location << 3));
        for ($i = 0; $i < 8;
             $i++) {
            $this->write($charmap[$i]);
        }
    }

    public function print(string $s)
    {
      for($i=0, $l = strlen($s);$i<$l; ++$i) {
          $this->write(ord($s[$i]));
      }
    }

    /*********** mid level commands, for sending data/cmds */

    public function command(int $value)
    {
        $this->send($value, PinInterface::VALUE_LOW);
    }

    public function write(int $value): int
    {
        $this->send($value, PinInterface::VALUE_HIGH);

        return 1; // assume sucess
    }

    /************ low level data pushing commands **********/

// write either command or data, with automatic 4/8-bit selection
    public function send(int $value, int $mode)
    {
        $this->_rs_pin->setValue($mode);

        // if there is a RW pin indicated, set it low to Write
        if ($this->_rw_pin != null) {
            $this->_rw_pin->setValue(PinInterface::VALUE_LOW);
        }

        if ($this->_displayfunction & self::LCD_8BITMODE) {
            $this->write8bits($value);
        } else {
            $this->write4bits($value >> 4);
            $this->write4bits($value);
        }
    }

    public function pulseEnable()
    {
        $this->_enable_pin->setValue(PinInterface::VALUE_LOW);
        usleep(1);
        $this->_enable_pin->setValue(PinInterface::VALUE_HIGH);
        usleep(1);    // enable pulse must be >450ns
        $this->_enable_pin->setValue(PinInterface::VALUE_LOW);
        usleep(100);   // commands need > 37us to settle
    }

    public function write4bits(int $value)
    {
        for ($i = 0; $i < 4;
             $i++) {
            $this->_data_pins[$i]->setValue(($value >> $i) & 0x01);
        }

        $this->pulseEnable();
    }

    public function write8bits(int $value)
    {
        for ($i = 0; $i < 8;
             $i++) {
            $this->_data_pins[$i]->setValue(($value >> $i) & 0x01);
        }

        $this->pulseEnable();
    }

}
