<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use DateTime;

class AdminScoreController extends Controller
{
    /**
     * @Route("/adminscore", name="AdminScore")
     * @Route("/adminscore/", name="AdminScoreSlash")
     * @Route("/adminscore/index.php", name="AdminScoreIndexPhp")
     * @Route("/scottywong tools/adminscore.php", name="AdminScoreLegacy")
     * @Route("/adminscore/{project}", name="AdminScoreProject")
     */
    public function indexAction($project = null)
    {
        $lh = $this->get("app.labs_helper");

        $lh->checkEnabled("adminscore");

        // Grab the request object, grab the values out of it.
        $request = Request::createFromGlobals();

        $projectQuery = $request->query->get('project');
        $username = $request->query->get('user');

        if ($projectQuery != "" && $username != "") {
            return $this->redirectToRoute("AdminScoreResult", array('project'=>$projectQuery, 'username' => $username));
        }
        else if ($projectQuery != "") {
            return $this->redirectToRoute("AdminScoreProject", array('project'=>$projectQuery));
        }

        // Otherwise fall through.
        return $this->render('adminscore/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.root_dir').'/..'),
            "page" => "adminscore",
            "pageTitle" => "tool_adminscore",
            "subtitle" => "tool_adminscore_desc",

            "project" => $project,
        ]);
    }

    /**
     * @Route("/adminscore/{project}/{username}", name="AdminScoreResult")
     */
    public function resultAction($project, $username)
    {

        $lh = $this->get("app.labs_helper");

        $lh->checkEnabled("adminscore");

        $username = ucfirst($username);

        $dbValues = $lh->databasePrepare($project, "AdminScore");

        $dbName = $dbValues["dbName"];
        $wikiName = $dbValues["wikiName"];
        $url = $dbValues["url"];

        $userTable = $lh->getTable("user", $dbName);
        $pageTable = $lh->getTable("page", $dbName);
        $loggingTable = $lh->getTable("logging", $dbName);
        $revisionTable = $lh->getTable("revision", $dbName);
        $archiveTable = $lh->getTable("archive", $dbName);

        // MULTIPLIERS (to review)
        $ACCT_AGE_MULT = 1.25;   # 0 if = 365 jours
        $EDIT_COUNT_MULT = 1.25;     # 0 if = 10 000
        $USER_PAGE_MULT = 0.1;     # 0 if =
        $PATROLS_MULT = 1; # 0 if =
        $BLOCKS_MULT = 1.4;     # 0 if = 10
        $AFD_MULT = 1.15;
        $RECENT_ACTIVITY_MULT = 0.9;     # 0 if =
        $AIV_MULT = 1.15;
        $EDIT_SUMMARIES_MULT = 0.8;   # 0 if =
        $NAMESPACES_MULT = 1.0;     # 0 if =
        $PAGES_CREATED_LIVE_MULT = 1.4; # 0 if =
        $PAGES_CREATED_ARCHIVE_MULT = 1.4; # 0 if =
        $RPP_MULT = 1.15;     # 0 if =
        $USERRIGHTS_MULT = 0.75;   # 0 if =

        // Grab the connection to the replica database (which is separate from the above)
        $conn = $this->get('doctrine')->getManager("replicas")->getConnection();

        // Prepare the query and execute
        $resultQuery = $conn->prepare( "
		SELECT 'id' as source, user_id as value FROM $userTable WHERE user_name = :username
		UNION
        SELECT 'acct_age' as source, user_registration as value FROM $userTable WHERE user_name=:username
        UNION
        SELECT 'edit_count' as source, user_editcount as value FROM $userTable WHERE user_name=:username
        UNION
        SELECT 'user_page' as source, page_len as value FROM $pageTable WHERE page_namespace=2 AND page_title=:username
        UNION
        SELECT 'patrols' as source, COUNT(*) as value FROM $loggingTable WHERE log_type='patrol' and log_action='patrol' and log_namespace=0 and log_deleted=0 and log_user_text=:username
        UNION
        SELECT 'blocks' as source, COUNT(*) as value FROM $loggingTable WHERE log_type='block' AND log_action='block' AND log_namespace=2 AND log_deleted=0 AND log_title=:username
        UNION
        SELECT 'afd' as source, COUNT(*) as value FROM $revisionTable WHERE rev_page like 'Articles for deletion/%' and rev_page not like 'Articles_for_deletion/Log/%' and rev_user_text=:username
        UNION
        SELECT 'recent_activity' as source, COUNT(*) as value FROM $revisionTable WHERE rev_user_text=:username AND rev_timestamp > (now()-INTERVAL 730 day) and rev_timestamp < now()
        UNION
        SELECT 'aiv' as source, COUNT(*) as value FROM $revisionTable WHERE rev_page like 'Administrator intervention against vandalism%' and rev_user_text=:username
        UNION
        SELECT 'edit_summaries' as source, COUNT(*) as value FROM $revisionTable JOIN page ON rev_page=page_id WHERE page_namespace=0 AND rev_user_text=:username
        UNION
        SELECT 'namespaces' as source, count(*) as value FROM $revisionTable JOIN page ON rev_page=page_id WHERE rev_user_text=:username AND page_namespace=0
        UNION
	    SELECT 'pages_created_live' as source, COUNT(*) as value from $revisionTable where rev_user_text=:username and rev_parent_id=0
		UNION
		SELECT 'pages_created_archive' as source, COUNT(*) as value from $archiveTable where ar_user_text=:username and ar_parent_id=0
        UNION
        SELECT 'rpp' as source, COUNT(*) as value FROM $revisionTable WHERE rev_page like 'Requests_for_page_protection%' and rev_user_text=:username
        ");

        $resultQuery->bindParam("username", $username);
        $resultQuery->execute();

        // Fetch the result data
        $results = $resultQuery->fetchAll();

        $master = [];
        $total = 0;

        $id = 0;

        foreach($results as $row) {
            $key = $row["source"];
            $value = $row["value"];

            if ($key == "acct_age") {
                $now = new DateTime();
                $date = new DateTime($value);
                $diff = $date->diff($now);
                $formula = 365*$diff->format("%y")+30*$diff->format("%m")+$diff->format("%d");
                $value = $formula-365;

            }

            if ($key == "id") {
                $id = $value;
            }
            else {
                $multiplierKey = strtoupper($row["source"] . "_MULT");
                $multiplier = (isset($$multiplierKey) ? $$multiplierKey : 1);
                $score = max(min($value * $multiplier, 100), -100);
                $master[$key]["mult"] = $multiplier;
                $master[$key]["value"] = $value;
                $master[$key]["score"] = $score;
                $total += $score;

            }
        }

        if ($id == 0) {
            $this->addFlash("notice", ["noresult", $username]);
            return $this->redirectToRoute("AdminScore", ["project"=>$project]);
        }


        // replace this example code with whatever you need
        return $this->render('adminscore/result.html.twig', array(
            "page" => "adminscore",
            "title" => "tool_adminscore",
            "pageTitle" => "tool_adminscore",
            "subtitle" => "tool_adminscore_desc",
            'url' => $url,
            'username' => $username,
            'project' => $wikiName,
            'master' => $master,
            'total' => $total,
        ));
    }
}
