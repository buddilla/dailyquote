<?php
//  $Id$
/**
*   Class to handle quotes
*   @author     Lee Garner <lee@leegarner.com>
*   @copyright  Copyright (c) 2009 Lee Garner <lee@leegarner.com>
*   @package    dailyquote
*   @version    0.0.1
*   @license    http://opensource.org/licenses/gpl-2.0.php 
*   GNU Public License v2 or later
*   @filesource
*/

/**
*   Define a class to deal with quotes
*   @package dailyquote
*/
class DailyQuote
{
    /** Quote Database ID
     *  @var string */
    var $id;

    /** Quote Text
     *  @var string */
    var $quote;

    /** Who is quoted
     *  @var string */
    var $quoted;

    /** Title of quote
     *  @var string */
    var $title;

    /** Source (book, web site, etc)
     *  @var string */
    var $source;

    /** Date of the source
     *  @var string */
    var $sourcedate;

    /** Date of the quote entry
     *  @var integer */
    var $dt;

    /** User ID of submitter
     *  @var integer */
    var $uid;

    /** Enabled
     *  @var integer */
    var $enabled;

    /** Is this a new submission?
     *  @var boolean */
    var $isNew;


    /**
     *  Constructor.
     *
     *  @param string $id Quote ID to retrieve, blank for empty object
     */
    function __construct($id='')
    {
        $this->id = COM_sanitizeID($id, false);
        $this->isNew = true;
        if ($this->id != '') {
            $this->Read($this->id);
        }
    }


    /**
     *  Read a quote record from the database.
     *  If no quote ID is specified, a random quote is read.
     *
     *  @param  string  $qid    Optional quote ID to read
     */
    public function Read($qid = '')
    {
        global $_TABLES;

        $sql = "SELECT * 
                FROM {$_TABLES['dailyquote_quotes']} ";

        if ($qid == '') {
            $sql .= ' ORDER BY RAND() LIMIT 1';
        } else {
            $qid = COM_sanitizeID($qid, false);
            $sql .= " WHERE id='$qid'";
        }

        $A = DB_fetchArray(DB_query($sql));
        $this->setVars($A);
        $this->isNew = false;
    }


    /**
     *  Set the variables from the supplied array.
     *  The array may be from a form ($_POST) or database record.
     *
     *  @param  array   $A  Array of values
     */
    public function setVars($A)
    {
        if (!is_array($A))
            return;

        $this->id = COM_sanitizeID($A['id'], true);
        $this->quote = $A['quote'];
        $this->quoted = $A['quoted'];
        $this->source = $A['source'];
        $this->sourcedate = $A['sourcedate'];
        $this->title = $A['title'];
        $this->dt = $A['dt'];
        $this->enabled = (isset($A['enabled']) && $A['enabled'] == 0) ? 0 : 1;
        $this->uid = (int)$A['uid'];

    }


    function GetID()
    {   return $this->id;   }


    /**
     *  Update the 'enabled' value for a quote.
     *
     *  @param  integer $newval     New value to set (1 or 0)
     *  @param  string  $bid        Optional ad ID.  Current object if blank
     */
    public function toggleEnabled($newval, $id='')
    {
        global $_TABLES;

        if ($id == '') {
            if (is_object($this)) {
                $id = $this->id;
                if ($this->Access() < 3)
                    return;
            } else {
                return;
            }
        }

        $newval = $newval == 0 ? 0 : 1;
        DB_change($_TABLES['dailyquote_quotes'],
                'enabled', $newval,
                'id', DB_escapeString(trim($id)));
    }


    /**
     *  Delete a quote.
     *  Deletes the supplied quote ID if not empty, otherwise
     *  deletes the current object.
     *
     *  @param  string  $bid    Optional quote ID to delete
     */
    public function Delete($id='', $table='dailyquote_quotes')
    {
        global $_TABLES;

        if ($id == '') {
            if (is_object($this)) {
                $id = $this->id;
            } else {
                return;
            }
        }

        if ($table != 'dailyquote_quotes')
            $table = 'dailyquote_submission';

        if (!DailyQuote::hasAccess(3))
            return;

        $id = COM_sanitizeID($id, false);
        DB_delete($_TABLES[$table],
            'id', $id);

        DB_delete($_TABLES['dailyquote_quoteXcat'],
            'qid', $id);
    }


    /**
     *  Returns the current user's access level to this quote.
     *
     *  @return integer     User's access level (1 - 3)
     */
    public function Access($isNew = false)
    {
        global $_USER, $_CONF_DQ;

        if (SEC_hasRights('dailyquote.edit'))
            return 3;

        if ($isNew) {
            if (SEC_hasRights('dailyquote.submit')) {
                $access = 3;
            } elseif (COM_isAnonUser()) {
                $access = $_CONF_DQ['anonadd'] == 1 ? 3 : 0;
            } else {
                $access = $_CONF_DQ['loginadd'] == 1 ? 3 : 0;
            } 
        } else {
            if (COM_isAnonUser()) {
                $access = $_CONF_DQ['anonview'] == 1 ? 2 : 0;
            } else {
                $access = 2;       // Logged-in can always view
            }
        }

        return $access;

    }


    /**
     *  Determines whether the current user has a given level of access
     *  to this quote object.
     *
     *  @see    Access()
     *  @param  integer $level  Minimum access level required
     *  @return boolean     True if user has access >= level, false otherwise
     */
    public function hasAccess($level=3, $isNew=false)
    {
        if (DailyQuote::Access($isNew) < $level) {
            return false;
        } else {
            return true;
        }
    }


    /**
     *  Save the current quote object using the supplied values.
     *
     *  @param  array   $A  Array of values from $_POST or database
     */
    public function Save($A, $table='dailyquote_quotes')
    {
        global $_CONF, $_TABLES, $_USER, $MESSAGE, $LANG_DQ, $_CONF_DQ;

        if (is_array($A))
            $this->setVars($A);

        if (empty($this->uid)) {
            // this is new quote from admin, set default values
            $uid = $_USER['uid'];
        }

        if ($table != 'dailyquote_quotes')
            $table = 'dailyquote_submission';

        $access = $this->hasAccess(3, $this->isNew);
        if (!$access || empty($this->id)) {
            COM_errorLog("User {$_USER['username']} tried to illegally submit or edit quote {$this->id}.");
            return COM_showMessageText($MESSAGE[31], $MESSAGE[30]);
        }

        // Determine if this is an INSERT or UPDATE
        if ($this->isNew) {
            $sql = "INSERT INTO {$_TABLES[$table]}
                    (id, dt, quote, quoted, title, source, sourcedate, uid)
                VALUES (
                    '{$this->id}',
                    " . time() . ",
                    '" . DB_escapeString(self::SafeText($A['quote'])). "',
                    '" . DB_escapeString(self::SafeText($A['quoted'])). "',
                    '" . DB_escapeString(self::SafeText($A['title'])) . "',
                    '" . DB_escapeString(self::SafeText($A['source'])) . "',
                    '" . DB_escapeString(self::SafeText($A['sourcedate'])) . "',
                    '" . (int)$A['uid'] . "'
            )";
        } else {
            $sql = "UPDATE {$_TABLES[$table]}
                SET
                    quote = '" . DB_escapeString(self::SafeText($A['quote'])). "',
                    quoted = '" . DB_escapeString(self::SafeText($A['quoted'])). "',
                    title = '" . DB_escapeString(self::SafeText($A['title'])) . "',
                    source = '" . DB_escapeString(self::SafeText($A['source'])) . "',
                    sourcedate = '" . DB_escapeString(self::SafeText($A['sourcedate'])) . "'
                WHERE
                    id = '" . DB_escapeString($this->id) . "'";
        }
        //echo $sql;die;
        DB_query($sql);

        // Delete all lookup records for this quote to make sure we
        // get rid of unused categories.
        DB_delete($_TABLES['dailyquote_quoteXcat'], 'qid', $A['id']);

        // Now, add records to the lookup table to link the categories
        // to the quote.  Only if bypassing the submission queue; if
        // the queue is used $catlist will be empty.
        if (!is_array($A['cat']) || empty($A['cat'])) {
            $A['cat'] = array(1 => 'Miscellaneous');
        }
        foreach($A['cat'] as $key => $name) {
            $key = (int)$key;
            $sql = "INSERT IGNORE INTO {$_TABLES['dailyquote_quoteXcat']}
                    (qid, cid)
                VALUES (
                    '{$this->id}', $key
                )";
            //echo $sql;
            @DB_query($sql);
        }

        return '';

    }


    /**
     *  Retrieves a single quote.  If $id is empty, a random quote is selected.
     *  If called within an instantiated object, then the object properties
     *  are populated.
     *
     *  @param  string  $qid    Optional quote specifier
     *  @param  string  $cid    Optional category specifier
     *  @return array           Values from quote table.
     */
    public function getQuote($qid='', $cid='')
    {
        global $_TABLES;

        // Sanitize category ID
        $cid = (int)$cid;

        //get random quote
        $sql = "SELECT  q.* FROM  {$_TABLES['dailyquote_quotes']} q";
        if ($cid > 0) {
            $sql .= " LEFT JOIN {$_TABLES['dailyquote_quoteXcat']} l
                    ON l.qid = q.id
                LEFT JOIN {$_TABLES['dailyquote_cat']} c
                    ON l.cid = c.id ";
        }
        $sql .= " WHERE 1=1 ";
        if ($qid == '') {
            if ($cid != '') {
                $sql .= " AND c.id = '$cid' ";
            }
            $sql .= " ORDER BY rand() LIMIT 1";
        } else {
            $sql .= " AND q.id='" . DB_escapeString($qid). "'";
        }
        //echo $sql;

        if (!$result = DB_query($sql)) {
            COM_errorLog("An error occured while retrieving your quote",1);
            return array();
        }

        $row = DB_fetchArray($result, false);
        //if (!empty($row))
        //    $row['quoted'] = DailyQuote::GoogleLink($row['quoted']);

        if (is_object($this))
            $this->SetVars($row);

        foreach (array('title', 'quote', 'quoted', 'source', 'sourcedate') as $i) {
            $row[$i] = htmlspecialchars($row[$i], ENT_QUOTES,COM_getEncodingt());
        }
        return $row;
    }


    /**
    *   Enclose the Quoted field in link tags for Google search.
    *
    *   @param  string  $Quoted     Person quoted
    *   @return string              URL for google search, or 'unknown'
    */
    private static function GoogleLink($Quoted)
    {
        global $_CONF, $_TABLES, $LANG_DQ, $_CONF_DQ;

        //do if based on setting
        if ($_CONF_DQ['google_link'] == 0 || empty($_CONF_DQ['google_url'])) {
            return $Quoted;
        }

        if ($Quoted == '') {
            return $LANG_DQ['unknown'];
        }

        $gname = urlencode(trim($Quoted));
        $retval = '<a href="' . 
            sprintf($_CONF_DQ['google_url'], $_CONF['iso_lang'], $gname) . 
                    '">' . $Quoted . '</a>';
        return $retval;
    }


    /**
    *   Sanitize text inputs. This is to sanitize text before saving to the DB.
    *
    *   @param  string  $str    String to be sanitized
    *   @return string      Sanitized string
    */
    private static function SafeText($str)
    {
        //return htmlspecialchars(COM_checkWords($str),ENT_QUOTES,COM_getEncodingt());
        return COM_checkWords($str);
    }

}   // class DailyQuote



?>
