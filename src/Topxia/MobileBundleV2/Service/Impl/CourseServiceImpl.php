<?php
namespace Topxia\MobileBundleV2\Service\Impl;

use Topxia\MobileBundleV2\Service\BaseService;
use Topxia\MobileBundleV2\Service\CourseService;
use Topxia\Common\ArrayToolkit;

class CourseServiceImpl extends BaseService implements CourseService
{
	public function getVersion()
	{
		var_dump("CourseServiceImpl->getVersion");
		return $this->formData;
	}

	public function postThread()
	{
		$courseId = $this->getParam("courseId", 0);
		$threadId = $this->getParam("threadId", 0);

		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', "您尚未登录，不能评价课程！");
        }
        $thread = $this->controller->getThreadService()->getThread($courseId, $threadId);
        if (empty($thread)) {
        	return $this->createErrorResponse('not_thread', "问答不存在或已删除");
        }

		$content = $this->getParam("content", '');
		$content = $this->uploadImage($content);

		$formData = $this->formData;
		var_dump($formData);
		$formData['content'] = $content;
		var_dump($formData);
		unset($formData['imageCount']);
		$post = $this->controller->getThreadService()->createPost($formData);
		return $post;
	}

	/*
	 *更新回复

	 *
	 *
	*/ 
	public function updatePost()
	{
		$courseId = $this->getParam("courseId", 0);
		$threadId = $this->getParam("threadId", 0);
		$postId = $this->getParam('postId', 0);

		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
	        return $this->createErrorResponse('not_login', "您尚未登录，不能评价课程！");
	    }

	    // if (empty($thread)) {
	    //     return $this->createErrorResponse('not_thread', "问答不存在或已删除");
	    // }

	    if($postId != 0) {
	    	$post = $this->controller->getThreadService()->getPost($courseId, $postId);
			var_dump($post);
		}

		$content = $this->getParam("content", '');
		$content = $this->uploadImage($content);

		$formData = $this->formData;
		$formData['content'] = $content;
		unset($formData['imageCount']);

		//$post = $this->controller->getThreadService()->updatePost($courseId,$postId,);
		return $post;
	}

	private function uploadImage($content)
	{
		$url = "none";
		$urlArray = array();
		$files = $file = $this->request->files;
		foreach ($files as $key => $value) {
			try {
				$group = $this->getParam("group", 'course');
				$record = $this->getFileService()->uploadFile($group, $value);
				$url = $this->controller->get('topxia.twig.web_extension')->getFilePath($record['uri']);
				
			} catch (\Exception $e) {
				$url = "error";
			}
			$urlArray[$key] = $url;
		}

		$baseUrl = $this->request->getSchemeAndHttpHost();
		$content = preg_replace_callback('/src=[\'\"](.*?)[\'\"]/', function($matches) use ($baseUrl, $urlArray) {
			return "src=\"{$baseUrl}/{$urlArray[$matches[1]]}\"";
		}, $content);
        		return $content;
	}

	public function commitCourse()
	{
		$courseId = $this->getParam("courseId", 0);
		$user = $this->controller->getUserByToken($this->request);

        		if (!$user->isLogin()) {
            		return $this->createErrorResponse($request, 'not_login', "您尚未登录，不能评价课程！");
        		}

        		$course = $this->controller->getCourseService()->getCourse($courseId);
        		if (empty($course)) {
            		return $this->createErrorResponse('not_found', "课程#{$courseId}不存在，不能评价！");
        		}

        		if (!$this->controller->getCourseService()->canTakeCourse($course)) {
            		return $this->createErrorResponse('access_denied', "您不是课程《{$course['title']}》学员，不能评价课程！");
        		}

        		$review = array();
        		$review['courseId'] = $course['id'];
        		$review['userId'] = $user['id'];
        		$review['rating'] = (float)$this->getParam("rating", 0);
        		$review['content'] = $this->getParam("content", '');

        		$review = $this->controller->getReviewService()->saveReview($review);
        		$review = $this->controller->filterReview($review);

        		return $review;
	}

	public function getCourseThreads()
	{
		$user = $this->controller->getUserByToken($this->request);
		$conditions = array(
            		'userId' => $user['id'],
            		'type' => 'question',
        		);

		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);
		$total = $this->controller->getThreadService()->searchThreadCount($conditions);

        		$threads = $this->controller->getThreadService()->searchThreads(
            		$conditions,
            		'createdNotStick',
            		$start,
            		$limit
        		);

        		$courses = $this->controller->getCourseService()->findCoursesByIds(ArrayToolkit::column($threads, 'courseId'));
        		return array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			'threads'=>$this->filterThreads($threads, $courses),
			);
	}

	private function filterThreads($threads, $courses)
	{
		if (empty($threads)) {
            		return array();
        		}

        		for ($i=0; $i < count($threads); $i++) { 
        			$thread = $threads[$i];
        			if (!isset($courses[$thread["courseId"]])) {
				unset($threads[$i]);
				continue;
			}
			$course = $courses[$thread['courseId']];
        			$threads[$i] = $this->filterThread($thread, $course, null);
        		}
        		return $threads;
	}

	private function filterThread($thread, $course, $user)
	{
		$thread["courseTitle"] = $course["title"];

        		$thread['coursePicture'] = $this->controller->coverPath($course["largePicture"], 'course-large.png');

        		$isTeacherPost = $this->controller->getThreadService()->findThreadElitePosts($course['id'], $thread['id'], 0, 100);
        		$thread['isTeacherPost'] = empty($isTeacherPost) ? false : true;
        		$thread['user'] = $user;
        		$thread['createdTime'] = date('c', $thread['createdTime']);
        		$thread['latestPostTime'] = date('c', $thread['latestPostTime']);

        		return $thread;
	}

	public function getThreadPost()
	{
		$courseId = $this->getParam("courseId", 0);
		$threadId = $this->getParam("threadId", 0);
		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);

		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
			return $this->createErrorResponse('not_login', '您尚未登录，不能查看该课时');
		}

		$total = $this->controller->getThreadService()->getThreadPostCount($courseId, $threadId);
		$posts = $this->controller->getThreadService()->findThreadPosts($courseId, $threadId, 'elite', $start, $limit);
		$users = $this->controller->getUserService()->findUsersByIds(ArrayToolkit::column($posts, 'userId'));
		return array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			"data"=>$this->filterPosts($posts, $this->controller->filterUsers($users))
			);
	}

	public function getThread()
	{
		$courseId = $this->getParam("courseId", 0);
		$threadId = $this->getParam("threadId", 0);
		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
			return $this->createErrorResponse('not_login', '您尚未登录，不能查看该课时');
		}

		$thread = $this->controller->getThreadService()->getThread($courseId, $threadId);
		if (empty($thread)) {
			return $this->createErrorResponse('no_thread', '没有找到指定问答!');
		}

		$course = $this->controller->getCourseService()->getCourse($thread['courseId']);
            	$user = $this->controller->getUserService()->getUser($thread['userId']);
		return $this->filterThread($thread, $course, $user);
	}

	public function getThreadTeacherPost()
	{
		$courseId = $this->getParam("courseId", 0);
		$threadId = $this->getParam("threadId", 0);

		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
			return $this->createErrorResponse('not_login', '您尚未登录，不能查看该课时');
		}

		$posts = $this->controller->getThreadService()->findThreadElitePosts($courseId, $threadId, 0, 100);
		$users = $this->controller->getUserService()->findUsersByIds(ArrayToolkit::column($posts, 'userId'));
		
		return $this->filterPosts($posts, $this->controller->filterUsers($users));
	}

	private function filterPosts($posts, $users)
	{
		return array_map(function($post) use ($users){
			$post['user'] = $users[$post['userId']];
			$post['createdTime'] = date('c', $post['createdTime']);
			return $post;
		}, $posts);
	}

	public function getFavoriteCoruse()
	{
		$user = $this->controller->getUserByToken($this->request);
		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);

		$total = $this->controller->getCourseService()->findUserFavoritedCourseCount($user['id']);
		$courses = $this->controller->getCourseService()->findUserFavoritedCourses($user['id'], $start, $limit);

		return array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			"data"=>$this->controller->filterCourses($courses)
			);
	}

	public function getCourseNotice()
	{
		$courseId = $this->getParam("courseId");
		if (empty($courseId)) {
			return array();
		}
		$announcements = $this->controller->getCourseService()->findAnnouncements($courseId, 0, 10);
		return $this->filterAnnouncements($announcements);
	}

	private function filterAnnouncements($announcements)
	{
		return array_map(function($announcement){
			unset($announcement["userId"]);
			unset($announcement["courseId"]);
			unset($announcement["updatedTime"]);
			$announcement["createdTime"] = date('Y-m-d h:i:s', $announcement['createdTime']);
			return $announcement;
		}, $announcements);
	}

	private function filterAnnouncement($announcement)
	{
		return $this->filterAnnouncements(array($announcement));
	}

	public function getReviews()
	{
		$courseId = $this->getParam("courseId");

		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);
		$total = $this->controller->getReviewService()->getCourseReviewCount($courseId);
		$reviews = $this->controller->getReviewService()->findCourseReviews($courseId, $start, $limit);
		$reviews = $this->controller->filterReviews($reviews);
		return array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			"data"=>$reviews
			);
	}


	public function favoriteCourse()
	{
        		$user = $this->controller->getUserByToken($this->request);
        		$courseId = $this->getParam("courseId");

        		if (empty($user) || !$user->isLogin()) {
            		return $this->createErrorResponse('not_login', "您尚未登录，不能收藏课程！");
        		}

        		if (!$this->controller->getCourseService()->hasFavoritedCourse($courseId)) {
            		$this->controller->getCourseService()->favoriteCourse($courseId);
        		}

        		return true;
	}

	public function getTeacherCourses()
	{
		$userId = $this->getParam("userId");
		if (empty($userId)) {
			return array();
		}
		$courses = $this->controller->getCourseService()->findUserTeachCourses(
	            	$userId, 0, 10
	        	);
		$courses = $this->controller->filterCourses($courses);
		return $courses;
	}

	public function unFavoriteCourse()
	{
		$user = $this->controller->getUserByToken($this->request);
        		$courseId = $this->getParam("courseId");

        		if (empty($user) || !$user->isLogin()) {
            		return $this->createErrorResponse('not_login', "您尚未登录，不能收藏课程！");
        		}

        		if (!$this->controller->getCourseService()->hasFavoritedCourse($courseId)) {
            		return $this->createErrorResponse('runtime_error', "您尚未收藏课程，不能取消收藏！");
        		}

        		$this->controller->getCourseService()->unfavoriteCourse($courseId);

        		return true;
	}

	public function vipLearn()
	{	
		if (!$this->controller->setting('vip.enabled')) {
            		return $this->createErrorResponse('error', "网校没有开启vip功能");
        		}
        		
        		$courseId = $this->getParam('courseId');
        		$user = $this->controller->getUserByToken($this->request);
        		if (!$user->isLogin()) {
            		return $this->createErrorResponse('not_login', "您尚未登录，不能收藏课程！");
        		}
        		$this->controller->getCourseService()->becomeStudent($courseId, $user['id'], array('becomeUseMember' => true));
        		return true;
	}

	public function coupon()
	{
		$code = $this->getParam('code');
		$type = $this->getParam('type');
		$courseId = $this->getParam('courseId');
            	//判断coupon是否合法，是否存在跟是否过期跟是否可用于当前课程
            	$course = $this->controller->getCourseService()->getCourse($courseId);
            	$couponInfo = $this->getCouponService()->checkCouponUseable($code, $type, $courseId, $course['price']);
            
            	return $couponInfo;
	}

	public function unLearnCourse()
	{	
		$courseId = $this->getParam("courseId");
        		$user = $this->controller->getUserByToken($this->request);
        		list($course, $member) = $this->controller->getCourseService()->tryTakeCourse($courseId);

        		if (empty($member)) {
            		return $this->createErrorResponse('not_member', '您不是课程的学员或尚未购买该课程，不能退学。');
        		}
        		if (!empty($member['orderId'])) {
        			$order = $this->getOrderService()->getOrder($member['orderId']);
	        		if (empty($order)) {
	            		return $this->createErrorResponse( 'order_error', '订单不存在，不能退学。');
	            	}

        			$reason = $this->getParam("reason", "");
        			$amount = $this->getParam("amount", 0);
        			$refund = $this->getCourseOrderService()->applyRefundOrder(
        				$member['orderId'], $amount, $reason, $this->getContainer());
        			if (empty($refund) || $refund['status'] != "success") {
        				return false;
        			}
        			return true;
        		}
        		
        		$this->getCourseService()->removeStudent($course['id'], $user['id']);
        		return true;
	}

	public function getCourse()
	{	
		$user = $this->controller->getUserByToken($this->request);
		$courseId = $this->getParam("courseId");
		$course = $this->controller->getCourseService()->getCourse($courseId);

		if (empty($course)) {
            		return $this->createErrorResponse('not_found', "课程不存在");
		}

		$member = $user->isLogin() ? $this->controller->getCourseService()->getCourseMember($course['id'], $user['id']) : null;
     		$member = $this->previewAsMember($member, $courseId, $user);
     		if ($member && $member['locked']) {
            		return $this->createErrorResponse('member_locked', "会员被锁住，不能访问课程，请联系管理员!");
     		}
        		if ($course['status'] != 'published') {
        			if (!$user->isLogin()){
            			return $this->createErrorResponse('course_not_published', "课程未发布或已关闭。");
        			}
        			if (empty($member)) {
            			return $this->createErrorResponse('course_not_published', "课程未发布或已关闭。");
        			}
        			$deadline = $member['deadline'] ;
        			$createdTime = $member['createdTime'];

        			if ($deadline !=0 && ($deadline - $createdTime) < 0) {
            			return $this->createErrorResponse('course_not_published', "课程未发布或已关闭。");
        			}
        		}

        		$userFavorited = $user->isLogin() ? $this->controller->getCourseService()->hasFavoritedCourse($courseId) : false;

		$vipLevels = $this->controller->getLevelService()->searchLevels(array('enabled' => 1), 0, 100);
        		return array(
        			"course"=>$this->controller->filterCourse($course),
        			"userFavorited"=>$userFavorited,
        			"member"=>$member,
        			"vipLevels"=>$vipLevels
        			);
	}

	public function searchCourse()
	{
		$search = $this->getParam("search", '');
		$conditions['title'] = $search;
		return $this->findCourseByConditions($conditions);
	}

	public function getCourses()
	{
		$categoryId = (int) $this->getParam("categoryId", 0);
		$conditions['categoryId'] = $categoryId;
		return $this->findCourseByConditions($conditions);
	}

	private function findCourseByConditions($conditions)
	{
		$conditions['status'] = 'published';
        		$conditions['type'] = 'normal';

		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);
		$total = $this->controller->getCourseService()->searchCourseCount($conditions);

		$sort = $this->getParam("sort", "latest");
		$conditions['sort'] = $sort;

        		$courses = $this->controller->getCourseService()->searchCourses($conditions, $sort, $start, $limit);
		$result = array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			"data"=>$this->controller->filterCourses($courses)
			);
		return $result;
	}

	public function getLearnedCourse()
	{
		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
            		return $this->createErrorResponse('not_login', "您尚未登录！");
        		}

		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);
        		$total = $this->controller->getCourseService()->findUserLeanedCourseCount($user['id']);
        		$courses = $this->controller->getCourseService()->findUserLeanedCourses($user['id'], $start, $limit);
        		
        		$result = array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			"data"=> $this->controller->filterCourses($courses)
			);
		return $result;
	}

	public function getLearningCourse()
	{
		$user = $this->controller->getUserByToken($this->request);
		if (!$user->isLogin()) {
            		return $this->createErrorResponse('not_login', "您尚未登录！");
        		}

		$start = (int) $this->getParam("start", 0);
		$limit = (int) $this->getParam("limit", 10);
        		$total = $this->controller->getCourseService()->findUserLeaningCourseCount($user['id']);
        		$courses = $this->controller->getCourseService()->findUserLeaningCourses($user['id'], $start, $limit);
        		
        		$result = array(
			"start"=>$start,
			"limit"=>$limit,
			"total"=>$total,
			"data"=> $this->controller->filterCourses($courses)
			);
		return $result;
	}
}