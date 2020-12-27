<?

class CPostingConfigGeneral {
    
    public static $IBLOCK_ID=3;
    public static $CITY='UF_PRISES';
    
    
    //get by ID
    function GetByID($ID) {
        global $DB;
        $ID = intval($ID);

        $strSql = "
			SELECT
				PC.*
			FROM r_posting_config PC
			WHERE PC.ID=" . $ID . "
		";

        return $DB->Query($strSql, false, "File: " . __FILE__ . "<br>Line: " . __LINE__);
    }
    
    // delete by ID
    function Delete($ID) {
        global $DB;
        $ID = intval($ID);

        $DB->StartTransaction();

        $res = $DB->Query("DELETE FROM r_posting_config WHERE ID='" . $ID . "'", false, "File: " . __FILE__ . "<br>Line: " . __LINE__);
        if ($res)
            $DB->Commit();
        else
            $DB->Rollback();

        return $res;
    }

    //Addition
    function Add($arFields) {
        global $DB;
        $ID = $DB->Add("r_posting_config", $arFields);
        return $ID;
    }

    //Update
    function Update($ID, $arFields) {
        global $DB;
        $ID = intval($ID);
        $strUpdate = $DB->PrepareUpdate("r_posting_config", $arFields);
        if ($strUpdate != "") {
            $strSql = "UPDATE r_posting_config SET " . $strUpdate . " WHERE ID=" . $ID;
            $res = $DB->Query($strSql, false, $err_mess . __LINE__);
            return $res;
        }
        return false;
    }


}

?>