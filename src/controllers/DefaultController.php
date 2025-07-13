<?php
/**
 * Play Tracker plugin for Craft CMS 3.x
 *
 * Tracks plays of videos.
 *
 * @link      https://mijingo.com
 * @copyright Copyright (c) 2018 Ryan Irelan
 */

namespace mijingo\playtracker\controllers;

use mijingo\playtracker\PlayTracker;

use Craft;
use craft\web\Controller;
use mijingo\playtracker\twigextensions\PlayTrackerTwigExtension;

/**
 *
 * @author    Ryan Irelan
 * @package   PlayTracker
 * @since     1.0.0
 */
class DefaultController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected array|bool|int $allowAnonymous = [
        'save',
        'mark-course-complete',      // Add course completion
        'mark-lesson-complete',      // Add lesson completion
        'mark-livestream-complete'   // Add livestream completion
    ];
    // Public Methods
    // =========================================================================


    public function actionGetPlayedVideos()
    {
        $params = craft::$app->request->getQueryParams();

        $videos = PlayTracker::$plugin->playTrackerService->getPlayedVideos($params['entryId']);

        return json_encode(array_column($videos, 'rowId'));
    }

    /**
     * Handle a request going to our plugin's actionDoSomething URL,
     * e.g.: actions/play-tracker/default/do-something
     *
     * @return mixed
     */
    public function actionSave()
    {

        // check that it's a logged in user session

        // get current user data
        $currentUserId = craft::$app->user->getId();
        // get data
        $params =  craft::$app->request->getBodyParams();
        $courseSlug = '';
        if (isset($params['courseUrlTitle'])) {
            $courseSlug = $params['courseUrlTitle'];
        }
        $save_data = array(
            'userId' => $currentUserId,
            'entryId' => $params['entryId'],
            'rowId' => $params['rowId'],
            'status' => $params['status'],
            'siteId' => $params['siteId'],
            'currentTimestamp' => $params['currentTimestamp'],
            'courseUrlTitle' => $courseSlug
        );

        $hasStarted = PlayTracker::$plugin->playTrackerService->hasStarted($save_data);
        $hasCompleted = PlayTracker::$plugin->playTrackerService->hasCompleted($save_data);

        if ($hasCompleted && $params['manualStatusUpdate'] === true)
        {
            return PlayTracker::$plugin->playTrackerService->updatePlay($save_data);
        }
        elseif ($hasStarted && !$hasCompleted) {
            return PlayTracker::$plugin->playTrackerService->updatePlay($save_data);
        }
        elseif (!$hasStarted && !$hasCompleted) {
            return  PlayTracker::$plugin->playTrackerService->savePlay($save_data, $params['manualStatusUpdate']);
        }
        else {
            return false;
        }
    }

    /**
     * Handle manual course completion
     * e.g.: actions/play-tracker/default/mark-course-complete
     */
    public function actionMarkCourseComplete(): mixed
    {
        // Get current user (matches your existing pattern)
        $currentUserId = craft::$app->user->getId();
        if (!$currentUserId) {
            return $this->asJson([
                'success' => false,
                'error' => 'User must be logged in'
            ]);
        }

        // Get data (matches your existing pattern)
        $params = craft::$app->request->getBodyParams();
        $entryId = $params['entryId'] ?? null;

        if (!$entryId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Missing entryId parameter'
            ]);
        }

        try {
            $success = PlayTracker::$plugin->playTrackerService->markCourseComplete($entryId, $currentUserId);

            return $this->asJson([
                'success' => $success,
                'message' => $success ? 'Course marked as completed!' : 'Failed to mark course complete'
            ]);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    /**
     * Handle manual lesson completion
     * e.g.: actions/play-tracker/default/mark-lesson-complete
     */
    public function actionMarkLessonComplete(): mixed
    {
        // Get current user (matches your existing pattern)
        $currentUserId = craft::$app->user->getId();
        if (!$currentUserId) {
            return $this->asJson([
                'success' => false,
                'error' => 'User must be logged in'
            ]);
        }

        // Get data (matches your existing pattern)
        $params = craft::$app->request->getBodyParams();
        $entryId = $params['entryId'] ?? null;

        if (!$entryId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Missing entryId parameter'
            ]);
        }

        try {
            // Use the same save logic as the save action
            $save_data = array(
                'userId' => $currentUserId,
                'entryId' => $entryId,
                'rowId' => 0, // Use 0 for lessons (not part of a course)
                'status' => 1, // 1 = completed
                'siteId' => craft::$app->sites->getCurrentSite()->id,
                'currentTimestamp' => time() * 1000,
                'courseUrlTitle' => ''
            );

            // Follow the same pattern as actionSave
            $hasStarted = PlayTracker::$plugin->playTrackerService->hasStarted($save_data);
            $hasCompleted = PlayTracker::$plugin->playTrackerService->hasCompleted($save_data);

            if ($hasCompleted) {
                return $this->asJson([
                    'success' => true,
                    'message' => 'Lesson already completed'
                ]);
            }
            elseif ($hasStarted && !$hasCompleted) {
                // Update existing record to completed
                $success = PlayTracker::$plugin->playTrackerService->updatePlay($save_data);
                return $this->asJson([
                    'success' => (bool)$success,
                    'message' => $success ? 'Lesson marked as completed!' : 'Failed to mark lesson complete'
                ]);
            }
            elseif (!$hasStarted && !$hasCompleted) {
                // Create new completed record
                $success = PlayTracker::$plugin->playTrackerService->savePlay($save_data, true);
                return $this->asJson([
                    'success' => (bool)$success,
                    'message' => $success ? 'Lesson marked as completed!' : 'Failed to mark lesson complete'
                ]);
            }

            return $this->asJson([
                'success' => false,
                'message' => 'Unable to mark lesson complete'
            ]);

        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle manual livestream completion
     * e.g.: actions/play-tracker/default/mark-livestream-complete
     */
    public function actionMarkLivestreamComplete(): mixed
    {
        // Get current user (matches your existing pattern)
        $currentUserId = craft::$app->user->getId();
        if (!$currentUserId) {
            return $this->asJson([
                'success' => false,
                'error' => 'User must be logged in'
            ]);
        }

        // Get data (matches your existing pattern)
        $params = craft::$app->request->getBodyParams();
        $entryId = $params['entryId'] ?? null;

        if (!$entryId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Missing entryId parameter'
            ]);
        }

        try {
            // Use the same save logic as the save action
            $save_data = array(
                'userId' => $currentUserId,
                'entryId' => $entryId,
                'rowId' => 0, // Use 0 for livestreams (not part of a course)
                'status' => 1, // 1 = completed
                'siteId' => craft::$app->sites->getCurrentSite()->id,
                'currentTimestamp' => time() * 1000,
                'courseUrlTitle' => ''
            );

            // Follow the same pattern as actionSave
            $hasStarted = PlayTracker::$plugin->playTrackerService->hasStarted($save_data);
            $hasCompleted = PlayTracker::$plugin->playTrackerService->hasCompleted($save_data);

            if ($hasCompleted) {
                return $this->asJson([
                    'success' => true,
                    'message' => 'Livestream already completed'
                ]);
            }
            elseif ($hasStarted && !$hasCompleted) {
                // Update existing record to completed
                $success = PlayTracker::$plugin->playTrackerService->updatePlay($save_data);
                return $this->asJson([
                    'success' => (bool)$success,
                    'message' => $success ? 'Livestream marked as completed!' : 'Failed to mark livestream complete'
                ]);
            }
            elseif (!$hasStarted && !$hasCompleted) {
                // Create new completed record
                $success = PlayTracker::$plugin->playTrackerService->savePlay($save_data, true);
                return $this->asJson([
                    'success' => (bool)$success,
                    'message' => $success ? 'Livestream marked as completed!' : 'Failed to mark livestream complete'
                ]);
            }

            return $this->asJson([
                'success' => false,
                'message' => 'Unable to mark livestream complete'
            ]);

        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}