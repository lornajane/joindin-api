<?php

class TalkCommentMapper extends ApiMapper
{
    public function getDefaultFields()
    {
        $fields = array(
            'rating'            => 'rating',
            'comment'           => 'comment',
            'user_display_name' => 'full_name',
            'username'          => 'username',
            'talk_title'        => 'talk_title',
            'created_date'      => 'date_made'
        );

        return $fields;
    }

    public function getVerboseFields()
    {
        $fields = array(
            'rating'            => 'rating',
            'comment'           => 'comment',
            'user_display_name' => 'full_name',
            'username'          => 'username',
            'talk_title'        => 'talk_title',
            'source'            => 'source',
            'created_date'      => 'date_made',
        );

        return $fields;
    }

    public function getCommentsByTalkId($talk_id, $resultsperpage, $start, $verbose = false)
    {
        $sql = $this->getBasicSQL();
        $sql .= 'and talk_id = :talk_id';
        $sql .= ' order by tc.date_made';

        $sql .= $this->buildLimit($resultsperpage, $start);
        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute(array(
            ':talk_id' => $talk_id
        ));
        if ($response) {
            $results          = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['total'] = $this->getTotalCount($sql, array(':talk_id' => $talk_id));
            $retval           = $this->transformResults($results, $verbose);

            return $retval;
        }

        return false;
    }

    public function getCommentsByEventId($event_id, $resultsperpage, $start, $verbose = false)
    {
        $sql = $this->getBasicSQL();
        $sql .= 'and event_id = :event_id ';

        // default to newest
        $sql .= ' order by tc.date_made desc';

        $sql .= $this->buildLimit($resultsperpage, $start);

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute(array(
            ':event_id' => $event_id
        ));
        if ($response) {
            $results          = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['total'] = $this->getTotalCount($sql, array(':event_id' => $event_id));
            $retval           = $this->transformResults($results, $verbose);

            return $retval;
        }

        return false;
    }

    public function getCommentsByUserId($user_id, $resultsperpage, $start, $verbose = false)
    {
        $sql = $this->getBasicSQL();
        $sql .= 'and tc.user_id = :user_id ';

        // default to newest
        $sql .= ' order by tc.date_made desc';

        $sql .= $this->buildLimit($resultsperpage, $start);

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute(array(
            ':user_id' => $user_id
        ));
        if ($response) {
            $results          = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results['total'] = $this->getTotalCount($sql, array(':user_id' => $user_id));
            $retval           = $this->transformResults($results, $verbose);

            return $retval;
        }

        return false;
    }

    public function getCommentById($comment_id, $verbose = false, $include_hidden = false)
    {
        $sql = $this->getBasicSQL($include_hidden);
        $sql .= ' and tc.ID = :comment_id ';
        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute(array(
            ':comment_id' => $comment_id
        ));
        if ($response) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $results['total'] = $this->getTotalCount($sql, array(':comment_id' => $comment_id));
                $retval           = $this->transformResults($results, $verbose);

                return $retval;
            }
        }

        return false;
    }

    public function transformResults($results, $verbose)
    {

        $total = $results['total'];
        unset($results['total']);
        $list    = parent::transformResults($results, $verbose);
        $base    = $this->_request->base;
        $version = $this->_request->version;

        // add per-item links
        if (is_array($list) && count($list)) {
            foreach ($results as $key => $row) {
                if (true === $verbose) {
                    $list[$key]['gravatar_hash'] = md5(strtolower($row['email']));
                }
                $list[$key]['uri'] = $base . '/' . $version . '/talk_comments/' . $row['ID'];
                $list[$key]['verbose_uri'] = $base . '/' . $version . '/talk_comments/' . $row['ID'] . '?verbose=yes';
                $list[$key]['talk_uri'] = $base . '/' . $version . '/talks/'. $row['talk_id'];
                $list[$key]['talk_comments_uri'] = $base . '/' . $version . '/talks/' . $row['talk_id'] . '/comments';
                $list[$key]['reported_uri'] = $base . '/' . $version . '/talk_comments/' . $row['ID'] . '/reported';
                if ($row['user_id']) {
                    $list[$key]['user_uri'] = $base . '/' . $version . '/users/' . $row['user_id'];
                }
            }
        }
        $retval             = array();
        $retval['comments'] = $list;
        $retval['meta']     = $this->getPaginationLinks($list, $total);

        return $retval;
    }

    /**
     * Template SQL for all comment-fetching queries
     *
     * @param bool $include_hidden If set to true, will return inactive and private comments also
     */
    protected function getBasicSQL($include_hidden = false)
    {
        $sql = 'select tc.*, '
               . 'user.username, user.email, user.full_name, t.talk_title, e.event_tz_cont, e.event_tz_place '
               . 'from talk_comments tc '
               . 'inner join talks t on t.ID = tc.talk_id '
               . 'inner join events e on t.event_id = e.ID '
               . 'left join user on tc.user_id = user.ID '
               . 'where 1 ';

        if (!$include_hidden) {
            $sql .= 'and tc.active = 1 and tc.private <> 1 ';
        }

        return $sql;
    }

    public function save($data)
    {
        // check for a duplicate first
        $dupe_sql = 'select tc.ID from talk_comments tc '
                    . 'where talk_id = :talk_id and user_id = :user_id and comment = :comment';

        $dupe_stmt = $this->_db->prepare($dupe_sql);
        $dupe_stmt->execute(array(
            ':talk_id' => $data['talk_id'],
            ':comment' => $data['comment'],
            ':user_id' => $data['user_id'],
        ));

        // only proceed if we didn't already find a row like this
        if ($dupe_stmt->fetch()) {
            throw new Exception("Duplicate comment");
        }

        $sql = 'insert into talk_comments (talk_id, rating, comment, user_id, '
               . 'source, date_made, private, active) '
               . 'values (:talk_id, :rating, :comment, :user_id, :source, UNIX_TIMESTAMP(), '
               . ':private, 1)';

        $stmt     = $this->_db->prepare($sql);
        $response = $stmt->execute(array(
            ':talk_id' => $data['talk_id'],
            ':rating'  => $data['rating'],
            ':comment' => $data['comment'],
            ':user_id' => $data['user_id'],
            ':private' => $data['private'],
            ':source'  => $data['source'],
        ));

        $comment_id = $this->_db->lastInsertId();

        return $comment_id;
    }

    /**
     * Has this user provided a rating for this talk which is greater than zero?
     *
     * @param  integer $user_id
     * @param  integer $talk_id
     *
     * @return boolean
     */
    public function hasUserRatedThisTalk($user_id, $talk_id)
    {
        $sql = 'select ID from talk_comments '
               . 'where talk_id = :talk_id and user_id = :user_id and rating > 0';

        $stmt = $this->_db->prepare($sql);
        $stmt->execute(array(
            ':talk_id' => $talk_id,
            ':user_id' => $user_id,
        ));

        if ($stmt->fetch()) {
            return true;
        }

        return false;
    }

    /**
     * Get the event and talk ID this talk comment belongs to
     *
     * @param int $comment_id The comment in question
     * @return array Some info
     */
    public function getCommentInfo($comment_id)
    {
        $sql = "select t.event_id, tc.talk_id
            from talk_comments tc
            join talks t on t.ID = tc.talk_id
            where tc.ID = :talk_comment_id";

        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["talk_comment_id" => $comment_id]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $row;
        }

        return false;
    }

    /**
     * A comment has been reported.  Record the report and hide the comment
     * pending moderation
     *
     * @param int $comment_id the comment that was reported
     * @param int $user_id the user that reported it
     */
    public function userReportedComment($comment_id, $user_id)
    {
        $report_sql = "insert into reported_talk_comments
            set talk_comment_id = :talk_comment_id,
            reporting_user_id = :user_id,
            reporting_date = NOW()";

        $report_stmt = $this->_db->prepare($report_sql);
        $result = $report_stmt->execute([
            "talk_comment_id" => $comment_id,
            "user_id" => $user_id]);

        $hide_sql = "update talk_comments
            set active = 0 where ID = :talk_comment_id";
        $hide_stmt = $this->_db->prepare($hide_sql);
        $result = $hide_stmt->execute(["talk_comment_id" => $comment_id]);
    }

    /**
     * A reported comment is being moderated
     * Approved means that the report is valid and the comment will not be shown
     *
     * @param int $comment_id the comment that was reported
     * @param int $user_id the moderating user
     */
    public function commentReportApproved($comment_id, $user_id)
    {
        $report_sql = "update reported_talk_comments
            set deciding_user_id = :user_id,
            deciding_date = NOW(),
            decision = \"approved\"
            WHERE talk_comment_id = :talk_comment_id";

        $report_stmt = $this->_db->prepare($report_sql);
        $result = $report_stmt->execute([
            "talk_comment_id" => $comment_id,
            "user_id" => $user_id]);

        return true;
    }
    /**
     * A reported comment is being moderated
     * Denied means that the report is rejected and the comment reinstated
     *
     * @param int $comment_id the comment that was reported
     * @param int $user_id the moderating user
     */
    public function commentReportDenied($comment_id, $user_id)
    {
        $report_sql = "update reported_talk_comments
            set deciding_user_id = :user_id,
            deciding_date = NOW(),
            decision = \"denied\"
            WHERE talk_comment_id = :talk_comment_id";

        $report_stmt = $this->_db->prepare($report_sql);
        $result = $report_stmt->execute([
            "talk_comment_id" => $comment_id,
            "user_id" => $user_id]);

        $show_sql = "update talk_comments
            set active = 1 where ID = :talk_comment_id";
        $show_stmt = $this->_db->prepare($show_sql);
        $result = $show_stmt->execute(["talk_comment_id" => $comment_id]);
        return true;
    }
}
