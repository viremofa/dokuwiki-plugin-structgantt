<?php

namespace dokuwiki\plugin\structgantt\meta;

use dokuwiki\plugin\struct\meta\Column;
use dokuwiki\plugin\struct\meta\SearchConfig;
use dokuwiki\plugin\struct\meta\StructException;
use dokuwiki\plugin\struct\meta\Value;
use dokuwiki\plugin\struct\types\Color;
use dokuwiki\plugin\struct\types\Date;
use dokuwiki\plugin\struct\types\DateTime;

class Gantt {

    /**
     * @var string the page id of the page this is rendered to
     */
    protected $id;
    /**
     * @var string the Type of renderer used
     */
    protected $mode;
    /**
     * @var \Doku_Renderer the DokuWiki renderer used to create the output
     */
    protected $renderer;
    /**
     * @var SearchConfig the configured search - gives access to columns etc.
     */
    protected $searchConfig;

    /**
     * @var Column[] the list of columns to be displayed
     */
    protected $columns;

    /**
     * @var  Value[][] the search result
     */
    protected $result;

    /**
     * @var int number of all results
     */
    protected $resultCount;

    /**
     * @var string[] the result PIDs for each row
     */
    protected $resultPIDs;

    /** @var int column number containing the start date */
    protected $colrefStart = -1;

    /** @var int column number containing the end date */
    protected $colrefEnd = -1;

    /** @var int column number containing the color */
    protected $colrefColor = -1;

    /** @var int column number containing the label */
    protected $labelRef = -1;

    /** @var int column number containing the title */
    protected $titleRef = -1;

    /** @var  string first date */
    protected $minDate;

    /** @var  string last date */
    protected $maxDate;

    /** @var  int days */
    protected $days;

    /**
     * Initialize the Aggregation renderer and executes the search
     *
     * You need to call @see render() on the resulting object.
     *
     * @param string $id
     * @param string $mode
     * @param \Doku_Renderer $renderer
     * @param SearchConfig $searchConfig
     */
    public function __construct($id, $mode, \Doku_Renderer $renderer, SearchConfig $searchConfig) {
        $this->id = $id;
        $this->mode = $mode;
        $this->renderer = $renderer;
        $this->searchConfig = $searchConfig;
        $this->columns = $searchConfig->getColumns();
        $this->result = $this->searchConfig->execute();
        $this->resultCount = $this->searchConfig->getCount();

        $this->initColumnRefs();
        $this->initMinMax();
    }

    /**
     * Figure out which columns will be used for dates and color
     *
     * The first date column is the start, the second is the end
     *
     * @todo suport Lookups pointing to dates and colors
     * @todo handle multi columns
     */
    protected function initColumnRefs() {
        $ref = 0;
        foreach($this->columns as $column) {
            if(
                is_a($column->getType(), Date::class) ||
                is_a($column->getType(), DateTime::class)
            ) {
                if($this->colrefStart == -1) {
                    $this->colrefStart = $ref;
                } else {
                    $this->colrefEnd = $ref;
                }
            } elseif(is_a($column->getType(), Color::class)) {
                $this->colrefColor = $ref;
            } else if($this->labelRef == -1) {
                $this->labelRef = $ref;
            } else if($this->titleRef == -1) {
                $this->titleRef = $ref;
            }
            $ref++;
        }

        if($this->colrefStart === -1 || $this->colrefEnd === -1) {
            throw new StructException('Not enough Date columns selected');
        }

        if($this->labelRef === -1) {
            throw new StructException('No label column found');
        }

        if($this->titleRef === -1) {
            $this->titleRef = $this->labelRef;
        }
    }

    protected function initMinMax() {
        $min = PHP_INT_MAX;
        $max = 0;

        /** @var Value[] $row */
        foreach($this->result as $row) {
            $start = $row[$this->colrefStart]->getCompareValue();
            $start = explode(' ', $start); // cut off time
            $start = array_shift($start);
            if($start && $start < $min) $min = $start;
            if($start && $start > $max) $max = $start;

            $end = $row[$this->colrefEnd]->getCompareValue();
            $end = explode(' ', $end); // cut off time
            $end = array_shift($end);
            if($end && $end < $min) $min = $end;
            if($end && $end > $max) $max = $end;
        }

        $days = $this->countDays($min, $max);
        if($days <= 1) {
            throw new StructException('Not enough variation in dates to create a range');
        }

        $this->minDate = $min;
        $this->maxDate = $max;
        $this->days = $days;
    }

    public function render() {
        if($this->mode !== 'xhtml') {
            $this->renderer->cdata('no other renderer than xhtml supported for struct gantt');
            return;
        }

        // FIXME do we need ceil?

        $this->renderer->doc .= '<table class="plugin_structgantt">';

        $this->renderHeaders();

        $this->renderer->doc .= '<tbody>';
        foreach($this->result as $row) {
            $this->renderRow($row);
        }
        $this->renderer->doc .= '</tbody>';
        $this->renderer->doc .= '</table>';

        #$this->renderer->code(print_r($this->result, true));
    }

    /**
     * Get the color to use in this row
     *
     * @param Value[] $row
     * @return string
     */
    protected function getColorStyle($row) {
        if($this->colrefColor === -1) return '';
        $color = $row[$this->colrefColor]->getValue();
        $conf = $row[$this->colrefColor]->getColumn()->getType()->getConfig();
        if($color == $conf['default']) return '';
        return 'style="background-color:' . $color . '"';
    }

    /**
     * Render the headers
     *
     * Automatically decides on the scale
     */
    protected function renderHeaders() {
        // define the resolution
        if($this->days < 14) {
            $format = 'j'; // days
        } elseif($this->days < 60) {
            $format = 'W'; // week numbers
        } else {
            $format = 'F'; // months
        }
        $headers = $this->makeHeaders($this->minDate, $this->maxDate, $format);

        // set the width of each day
        $headwidth = 15;
        $daywidth = (100 - $headwidth) / $this->days;
        $this->renderer->doc .= '<colgroup>';
        $this->renderer->doc .= '<col style="width:' . $headwidth . '%"/>';
        for($i = 0; $i < $this->days; $i++) {
            $this->renderer->doc .= '<col style="width:' . $daywidth . '%"/>';
        }
        $this->renderer->doc .= '</colgroup>';

        // output the header
        $this->renderer->doc .= '<thead>';
        $this->renderer->doc .= '<th></th>';
        foreach($headers as $name => $days) {
            $this->renderer->doc .= '<th colspan="' . $days . '">' . $name . '</th>';
        }
        $this->renderer->doc .= '</thead>';
    }

    /**
     * Render one row in the  diagram
     *
     * @param Value[] $row
     */
    protected function renderRow($row) {
        $start = $row[$this->colrefStart]->getCompareValue();
        $end = $row[$this->colrefEnd]->getCompareValue();

        if($start && $end) {
            $r1 = $this->countDays($start, $this->minDate);
            $r2 = $this->countDays($end, $start);
            $r3 = $this->countDays($this->maxDate, $end);
        } else {
            $r1 = $this->days;
            $r2 = 0;
            $r3 = 0;
        }

        // header
        $this->renderer->doc .= '<tr>';
        $this->renderer->doc .= '<th>';
        $row[$this->labelRef]->render($this->renderer, $this->mode);
        $this->renderer->doc .= '</th>';

        // period before the task
        for($i = 0; $i < $r1; $i++) {
            $this->renderer->doc .= '<td></td>';
        }

        // the task itself
        if($r2) {
            $style = $this->getColorStyle($row);
            $this->renderer->doc .= '<td colspan="' . $r2 . '" class="task" ' . $style . '>';
            $row[$this->titleRef]->render($this->renderer, $this->mode);

            $this->renderer->doc .= '<dl class="flyout">';
            foreach($row as $value) {
                $this->renderer->doc .= '<dd>';
                $value->render($this->renderer, $this->mode);
                $this->renderer->doc .= '<dd>';

            }
            $this->renderer->doc .= '</dl>';

            $this->renderer->doc .= '</td>';
        }

        // period after the task
        for($i = 0; $i < $r3; $i++) {
            $this->renderer->doc .= '<td></td>';
        }

        $this->renderer->doc .= '</tr>';
    }

    protected function renderPopup($row) {

    }

    /**
     * Returns the number of days in the given period
     *
     * @link based on http://stackoverflow.com/a/31046319/172068
     * @param string $start as YYYY-MM-DD
     * @param string $end as YYYY-MM-DD
     * @param bool $skipWeekends
     * @return int
     */
    protected function countDays($start, $end, $skipWeekends = false) {
        if($start > $end) list($start, $end) = array($end, $start);
        $days = 0;

        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval('P1D'),
            new \DateTime($end)
        );

        /** @var \DateTime $date */
        foreach($period as $date) {
            if($skipWeekends && (int) $date->format('N') >= 6) {
                continue;
            } else {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Returns the headers
     *
     * @param string $start as YYYY-MM-DD
     * @param string $end as YYYY-MM-DD
     * @param string $format a format string as understood by date(), used for grouping
     * @param bool $skipWeekends
     * @return array
     */
    protected function makeHeaders($start, $end, $format, $skipWeekends = false) {
        if($start > $end) list($start, $end) = array($end, $start);
        $headers = array();

        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval('P1D'),
            new \DateTime($end)
        );

        /** @var \DateTime $date */
        foreach($period as $date) {
            if($skipWeekends && (int) $date->format('N') >= 6) {
                continue;
            } else {
                $ident = $date->format($format);
                if(!isset($headers[$ident])) {
                    $headers[$ident] = 1;
                } else {
                    $headers[$ident]++;
                }
            }
        }

        return $headers;
    }
}