<?php

namespace Biz\Classroom\Service\Impl;

use Biz\BaseService;
use AppBundle\Common\ArrayToolkit;
use Biz\User\Service\UserService;
use Biz\System\Service\LogService;
use Biz\Classroom\Dao\ClassroomDao;
use Codeages\Biz\Framework\Event\Event;
use Biz\Classroom\Dao\ClassroomReviewDao;
use Biz\Classroom\Service\ClassroomService;
use Biz\Classroom\Service\ClassroomReviewService;

class ClassroomReviewServiceImpl extends BaseService implements ClassroomReviewService
{
    public function getReview($id)
    {
        return $this->getClassroomReviewDao()->get($id);
    }

    public function searchReviews($conditions, $orderBy, $start, $limit)
    {
        $conditions = $this->_prepareReviewSearchConditions($conditions);

        // $orderBy = empty($orderBy) ? $orderBy : array($orderBy[0] => $orderBy[1]);

        return $this->getClassroomReviewDao()->search($conditions, $orderBy, $start, $limit);
    }

    public function searchReviewCount($conditions)
    {
        $conditions = $this->_prepareReviewSearchConditions($conditions);
        $count      = $this->getClassroomReviewDao()->count($conditions);

        return $count;
    }

    public function getUserClassroomReview($userId, $classroomId)
    {
        $classroom = $this->getClassroomDao()->get($classroomId);

        if (empty($classroom)) {
            throw $this->createServiceException("Classroom is not Exist!");
        }

        return $this->getClassroomReviewDao()->getByUserIdAndClassroomId($userId, $classroomId);
    }

    private function _prepareReviewSearchConditions($conditions)
    {
        $conditions = array_filter($conditions, function ($value) {
            if (is_array($value) || is_numeric($value)) {
                return true;
            }

            return !empty($value);
        }

        );

        if (isset($conditions['author'])) {
            $author               = $this->getUserService()->getUserByNickname($conditions['author']);
            $conditions['userId'] = $author ? $author['id'] : -1;
        }

        return $conditions;
    }

    public function saveReview($fields)
    {
        if (!ArrayToolkit::requireds($fields, array('classroomId', 'userId', 'rating'))) {
            throw $this->createServiceException('参数不正确，评价失败！');
        }

        $classroom = $this->getClassroomDao()->get($fields['classroomId']);

        if (empty($classroom)) {
            throw $this->createServiceException("班级(#{$fields['classroomId']})不存在，评价失败！");
        }

        $user = $this->getUserService()->getUser($fields['userId']);

        if (empty($user)) {
            throw $this->createServiceException("用户(#{$fields['userId']})不存在,评价失败!");
        }

        $review = $this->getClassroomReviewDao()->getByUserIdAndClassroomId($user['id'], $classroom['id']);

        $fields['parentId'] = empty($fields['parentId']) ? 0 : $fields['parentId'];
        if (empty($review) || ($review && $fields['parentId'] > 0)) {
            $review = $this->getClassroomReviewDao()->create(array(
                'userId'      => $fields['userId'],
                'classroomId' => $fields['classroomId'],
                'rating'      => $fields['rating'],
                'content'     => empty($fields['content']) ? '' : $fields['content'],
                'title'       => empty($fields['title']) ? '' : $fields['title'],
                'parentId'    => $fields['parentId'],
                'meta'        => array()
            ));
            $this->dispatchEvent('classReview.add', new Event($review));
        } else {
            $review = $this->getClassroomReviewDao()->update($review['id'], array(
                'rating'      => $fields['rating'],
                'title'       => empty($fields['title']) ? '' : $fields['title'],
                'content'     => empty($fields['content']) ? '' : $fields['content'],
                'updatedTime' => time(),
                'meta'        => array()
            ));
        }

        $this->calculateClassroomRating($classroom['id']);

        return $review;
    }

    private function calculateClassroomRating($classroomId)
    {
        $ratingSum = $this->getClassroomReviewDao()->sumReviewRatingByClassroomId($classroomId);
        $ratingNum = $this->getClassroomReviewDao()->countReviewByClassroomId($classroomId);

        $this->getClassroomService()->updateClassroom($classroomId, array(
            'rating'    => $ratingNum ? $ratingSum / $ratingNum : 0,
            'ratingNum' => $ratingNum
        ));
    }

    public function deleteReview($id)
    {
        $review = $this->getReview($id);

        if (empty($review)) {
            throw $this->createServiceException("评价(#{$id})不存在，删除失败！");
        }

        $this->getClassroomReviewDao()->delete($id);

        $this->calculateClassroomRating($review['classroomId']);

        $this->getLogService()->info('classroom_review', 'delete', "删除评价#{$id}");
    }

    /**
     * @return ClassroomReviewDao
     */
    protected function getClassroomReviewDao()
    {
        return $this->createDao('Classroom:ClassroomReviewDao');
    }

    /**
     * @return ClassroomService
     */
    private function getClassroomService()
    {
        return $this->createService('Classroom:ClassroomService');
    }

    /**
     * @return ClassroomDao
     */
    protected function getClassroomDao()
    {
        return $this->createDao('Classroom:ClassroomDao');
    }

    /**
     * @return UserService
     */
    private function getUserService()
    {
        return $this->createService('User:UserService');
    }

    /**
     * @return LogService
     */
    private function getLogService()
    {
        return $this->createService('System:LogService');
    }
}