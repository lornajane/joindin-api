<?php

// @codingStandardsIgnoreStart
class Talk_commentsController extends ApiController
// @codingStandardsIgnoreEnd
{
    public function getComments($request, $db)
    {
        $comment_id = $this->getItemId($request);

        // verbosity
        $verbose = $this->getVerbosity($request);

        // pagination settings
        $start          = $this->getStart($request);
        $resultsperpage = $this->getResultsPerPage($request);

        $mapper = new TalkCommentMapper($db, $request);
        if ($comment_id) {
            $list = $mapper->getCommentById($comment_id, $verbose);
            if (false === $list) {
                throw new Exception('Comment not found', 404);
            }

            return $list;
        }

        return false;
    }

    /**
     * Any logged in user can report an inappropriate comment
     *
     */
    public function reportComment($request, $db)
    {
        // must be logged in to report a comment
        if (! isset($request->user_id) || empty($request->user_id)) {
            throw new Exception('You must log in to report a comment');
        }

        $comment_mapper = new TalkCommentMapper($db, $request);

        $commentId   = $this->getItemId($request);
        $commentInfo = $comment_mapper->getCommentInfo($commentId);
        if (false === $commentInfo) {
            throw new Exception('Comment not found', 404);
        }

        $talkId  = $commentInfo['talk_id'];
        $eventId = $commentInfo['event_id'];

        $comment_mapper->userReportedComment($commentId, $request->user_id);

        // notify event admins
        $comment      = $comment_mapper->getCommentById($commentId, true, true);
        $event_mapper = new EventMapper($db, $request);
        $recipients   = $event_mapper->getHostsEmailAddresses($eventId);

        $emailService = new CommentReportedEmailService($this->config, $recipients, $comment);
        $emailService->sendEmail();

        // send them to the comments collection
        $uri = $request->base . '/' . $request->version . '/talks/' . $talkId . "/comments";
        header("Location: " . $uri, true, 202);
        exit;
    }

    /**
     * Event and site admins can accept or refuse comment reports
     */
    public function moderateReportedComment($request, $db)
    {
        // must be logged in to report a comment
        if (! isset($request->user_id) || empty($request->user_id)) {
            throw new Exception('You must log in to report a comment');
        }

        // must have a decision field
        if(!isset($request->parameters['decision'])) {
            throw new Exception('The decision field is required');
        } elseif (strtolower($request->parameters['decision']) != "approved"
            && strtolower($request->parameters['decision']) != "denied") {
            throw new Exception('Decisions can be "approved" or "denied".  No other values are accepted');
        }

        $comment_mapper = new TalkCommentMapper($db, $request);
        $talk_mapper = new TalkMapper($db, $request);

        $commentId   = $this->getItemId($request);
        $commentInfo = $comment_mapper->getCommentInfo($commentId);
        if (false === $commentInfo) {
            throw new Exception('Comment not found', 404);
        }

        // can this user administer comments on this talk?
        $is_admin = $talk_mapper->thisUserHasAdminOn($commentInfo['talk_id']);
        if (! $is_admin) {
            throw new Exception("You do not have permission to do that", 403);
        }

        if($request->parameters['decision'] == "approved") {
            // approved meaning the report is approved and the comment will be deleted
            $comment_mapper->commentReportApproved($commentId, $request->user_id);
        } else {
            // denied meaning the comment is fine and will be restored
            $comment_mapper->commentReportDenied($commentId, $request->user_id);
        }

        // send them to the comments collection
        $uri = $request->base . '/' . $request->version . '/talks/' . $commentInfo['talk_id'] . "/comments";
        header("Location: " . $uri);
        exit;
    }
}
