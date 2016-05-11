<?php

class BotDao extends AbstractDao
{

// region -------------------- Users

    public function GetUserID($username)
    {
        $username = "'" . (isset($username) ? $this->escape_mimic($username) : '') . "'";
        $username = str_ireplace("@", "", $username);
        $res = $this->select("SELECT id FROM Users WHERE username=$username");
        return (!$res[0]) ? false : $res[0];
    }

    public function insertOrUpdateUser($user)
    {
        $user_id = $user['id'];
        $username = "'" . (isset($user['username']) ? $this->escape_mimic($user['username']) : '') . "'";
        $firstname = "'" . (isset($user['first_name']) ? $this->escape_mimic($user['first_name']) : '') . "'";
        $lastname = "'" . (isset($user['last_name']) ? $this->escape_mimic($user['last_name']) : '') . "'";
        $query = "INSERT INTO Users (id, username, firstname, lastname) VALUES ($user_id,$username,$firstname,$lastname) ON DUPLICATE KEY UPDATE username=$username, firstname=$firstname, lastname=$lastname";
        return $this->insert($query);
    }

    public function addChat($chat_id, $title, $chatType, $adder_id)
    {


        $title = "'" . (isset($title) ? $this->escape_mimic($title) : '') . "'";
        if (Util::isInEnum("group,supergroup", $chatType)) {
            $query = "INSERT INTO Chats (id,title) VALUES ($chat_id,$title) ON DUPLICATE KEY UPDATE title=$title";
            $res=$this->insert($query);
            //получение языка добавителя
            $pos= $this->GetLang($adder_id,"private");
            $this->setLang($chat_id, $chatType, $pos);
            Lang::init($pos);

            return $res;
        }

        return false;
    }

    public function getLang($id, $chatType)
    {
        $table = $this->getTable($chatType);
        if ($table === false) return false;

        $res = $this->select("SELECT lang FROM " . $table . " WHERE id=" . $id);

        return !($res[0]) ? false : $res[0];
    }

    /*
     * Type of chat, can be either “private”, “group”, “supergroup” or “channel”
     */

    private function getTable($chatType)
    {
        if ($chatType == "private") {
            $tableName = "Users";
        } elseif (Util::isInEnum("group,supergroup", $chatType)) {
            $tableName = "Chats";
        }
        if (!isset($tableName)) {
            return false;
        }
        return $tableName;

    }

    public function setLang($id, $chatType, $lang)
    {
        $table = $this->getTable($chatType);
        if ($table === false) return false;

        return $this->update("UPDATE " . $table . " SET lang = '" . $lang . "' WHERE id=" . $id);
    }


//endregion

// region -------------------- Chats

    public function DeleteChat($chat_id)
    {
        $query = "DELETE FROM `Chats` WHERE `id`=" . $chat_id;
        return $this->delete($query);
    }

    public function SetAdmin($chat_id, $user_id)
    {
        if ($user_id !== false) {
            $res = $this->insert("INSERT INTO `Admins` SET `user_id`='" . $user_id . "',`chat_id`=" . $chat_id);
            return ($res === false) ? false : "{username}, жду твоих указаний.";
        }
        return "Пользователь не найден";
    }

    public function CheckAdmin($chat_id, $user_id)
    {
        $res = $this->select("SELECT id FROM Admins WHERE chat_id=" . $chat_id . " AND user_id=" . $user_id);
        return $res[0];
    }


//endregion

// region -------------------- Admins

    public function getTop($chat_id, $limit = 5)
    {
        $query = "SELECT u.username, u.firstname, u.lastname, k.level FROM Karma k, Users u WHERE k.user_id=u.id AND k.chat_id=" . $chat_id . " ORDER BY level DESC LIMIT " . $limit;
        return $this->select($query);
    }

    public function Punish($user, $chat)
    {
        return $this->HandleKarma("-", BOT_NAME, $user, $chat);
    }





//endregion

//region -------------------- Karma

    public function HandleKarma($dist, $from, $to, $chat_id)
    {
        $fromLevel = 0;
        if ($from == $to) return "Давай <b>без</b> кармадрочерства";
        if ($from != BOT_NAME) {
            $fromLevelResult = $this->getUserLevel($from, $chat_id);
            if (!$fromLevelResult[0]) {
                $this->setUserLevel($from, $chat_id, 0);
                $fromLevel = 0;
            } else {
                $fromLevel = $fromLevelResult[0];
            };

            if ($fromLevel < 0) {
                return "Ты <b>не можешь</b> голосовать с отрицательной кармой";
            };
            $output = "<b>" . $this->GetUserName($from) . " (" . $fromLevel . ")</b>";
        } else {
            $output = "<b>Аксинья</b>";
        }
        $fromLevelSqrt = $fromLevel == 0 ? 1 : sqrt($fromLevel);

        $toLevelResult = $this->getUserLevel($to, $chat_id);
        $toLevel = !$toLevelResult[0] ? 0 : $toLevelResult[0];

        switch ($dist) {
            case "+":
                $output .= " плюсанул в карму ";
                $result = round($toLevel + $fromLevelSqrt, 1);
                break;
            case "-":
                $output .= " минусанул в карму ";
                $result = ($from != BOT_NAME) ? round($toLevel - $fromLevelSqrt, 1) : $toLevel - 0.1;
                break;
        }
        $output .= "<b>" . $this->GetUserName($to) . " (" . $result . ")</b>";
        $this->setUserLevel($to, $chat_id, $result);

        //проверка наград
        $output .= $this->handleRewards($result, $chat_id, $to);

        return $output;
    }

    /**
     * получить уровень кармы пользователя из чата
     * @param $user_id
     * @param $chat_id
     * @return mixed
     */
    public function getUserLevel($user_id, $chat_id)
    {
        $query = "SELECT level FROM Karma WHERE user_id=" . $user_id . " AND chat_id=" . $chat_id;
        $res = $this->select($query);
        return $res;
    }

    /**
     * Добавляет запись с уровня кармы пользователя в чате.
     * Если пользователь уже имеется с каким то левелом то левел обновится из параметра $level
     * @param $user_id
     * @param $chat_id
     * @param $level
     * @return mixed
     */
    public function setUserLevel($user_id, $chat_id, $level)
    {
        $query = "INSERT INTO `Karma` SET `user_id`=" . $user_id . ",`chat_id`=" . $chat_id . ",`level`=" . $level . " ON DUPLICATE KEY UPDATE `level`=" . $level;
        $res = $this->insert($query);
        return ($res === false) ? false : true;
    }

    public function GetUserName($id)
    {
        $res = $this->select("SELECT username,firstname,lastname FROM Users WHERE id=" . $id);
        return (!$res[0]) ? $res[1] : $res[0];
    }

    public function handleRewards($currentCarma, $chat_id, $user_id)
    {
        $output = "";
        //проверка наград
        switch ($currentCarma) {
            case $currentCarma >= 200 and $currentCarma < 500:
                $newType = 2;
                $title = "Кармодрочер";
                $min = 200;
                break;
            case $currentCarma >= 500 and $currentCarma < 1000:
                $newType = 3;
                $title = "Карманьяк";
                $min = 500;
                break;
            case $currentCarma >= 1000:
                $newType = 4;
                $title = "Кармонстр";
                $min = 1000;
                break;
            default:
                $title = "title";
                $min = "min";
                break;
        }
        $oldType = $this->getRewardOldType($user_id, $chat_id);
        if ($oldType != false) {
            //если есть награды
            if (isset($newType)) {
                if ($newType <> $oldType[0]) {
                    $desc = $this->generateRewardDesc($chat_id, $min);
                    $this->updateReward($newType, $oldType[0], $desc, $user_id, $chat_id);
                }
                if ($newType > $oldType[0]) {
                    $output .= $this->getRewardMessage($user_id, $title);
                }
            } else {
                $this->deleteReward($user_id, $chat_id);
            }
        } elseif (isset($newType)) {
            //Если нет наград, но
            $desc = $this->generateRewardDesc($chat_id, $min);
            $this->insertReward($newType, $desc, $user_id, $chat_id);
            $output .= $this->getRewardMessage($user_id, $title);
        }
        return $output;
    }

//endregion

//region -------------------- Rewards

    public function getRewardOldType($user_id, $chat_id)
    {
        return $this->select("SELECT type_id FROM Rewards WHERE user_id=" . $user_id . " AND group_id=" . $chat_id . " AND type_id>=2 AND type_id<=4");
    }

    public function generateRewardDesc($chat_id, $min)
    {
        return "Карма в группе " . $this->GetGroupName($chat_id) . " превысило отметку в " . $min;
    }

    public function GetGroupName($id)
    {
        $res = $this->select("SELECT title FROM Chats WHERE id=" . $id);
        return (!$res[0]) ? false : $res[0];
    }

    public function updateReward($new_type_id, $old_type_id, $desc, $user_id, $chat_id)
    {
        return $this->update("UPDATE Rewards SET type_id=" . $new_type_id . ", description='" . $desc . "' WHERE type_id=" . $old_type_id . " AND user_id=" . $user_id . " AND group_id=" . $chat_id);
    }

    public function getRewardMessage($user_id, $title)
    {
        return "\r\nТоварищ награждается отличительным знаком «<a href='" . PATH_TO_SITE . "?user_id=" . $user_id . "'>" . $title . "</a>»";
    }

    public function deleteReward($user_id, $chat_id)
    {
        return $this->delete("DELETE FROM Rewards WHERE user_id=" . $user_id . " AND group_id=" . $chat_id . " AND (type_id>=2 AND type_id<=4)");
    }

    public function insertReward($new_type_id, $desc, $user_id, $chat_id)
    {
        return $this->insert("INSERT INTO Rewards(type_id,user_id,group_id,description) VALUES (" . $new_type_id . "," . $user_id . "," . $chat_id . ",'" . $desc . "')");
    }

//endregion


}


?>