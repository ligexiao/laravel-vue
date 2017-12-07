<?php
namespace App\Repositories\Frontend;

use App\Models\Leave;
use Illuminate\Support\Facades\DB;

class LeaveRepository extends CommonRepository
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 留言列表
     * @param  Array $input [search_form]
     * @return Array
     */
    public function lists($input)
    {
        $search          = isset($input['search']) ? (array) $input['search'] : [];
        $result['lists'] = $this->getLeaveLists($search);
        return responseResult(true, $result);
    }

    /**
     * 获取列表
     * @return Object
     */
    public function getLeaveLists($search)
    {
        $dicts  = $this->getRedisDictLists(['audit' => ['pass']]);
        $result = Leave::where('is_audit', $dicts['audit']['pass'])->where('status', 1)->where('parent_id', 0)->with('user')->paginate();
        if ($result->isEmpty()) {
            return $result;
        }

        $leave_ids = [];
        foreach ($result as $index => $leave) {
            $leave_ids[] = $leave->id;
        }
        // 找出所有的回复
        if (!empty($leave_ids)) {
            $response_lists = Leave::whereIn('parent_id', $leave_ids)->with('user')->where('status', 1)->get();
            if (!empty($response_lists)) {
                $response_temp = [];
                foreach ($response_lists as $index => $response) {
                    $response_temp[$response->parent_id][] = $response;
                }
                foreach ($result as $index => $leave) {
                    $result[$index]['response'] = isset($response_temp[$leave->id]) ? $response_temp[$leave->id] : [];
                }
            }
        }
        return $result;
    }

    /**
     * 留言
     * @param  Array $input [leave_id, content] 留言数据
     * @return Array
     */
    public function leave($input)
    {
        $leave_id = isset($input['leave_id']) ? intval($input['leave_id']) : 0;
        $content  = isset($input['content']) ? strval($input['content']) : '';
        if (!$content) {
            return responseResult(false, [], '留言失败，参数错误，请刷新后重试');
        }

        $dicts = $this->getRedisDictLists(['system' => ['leave_audit'], 'audit' => ['loading', 'pass']]);
        // 表示回复
        if ($leave_id) {
            $list = Leave::where('id', $leave_id)->where('status', 1)->where('is_audit', $dicts['audit']['pass'])->first();
            if (empty($list)) {
                return responseResult(false, [], '留言失败，参数错误，请刷新后重试');
            }
        }
        $result['list'] = Leave::create([
            'user_id'    => $this->getCurrentId(),
            'parent_id'  => $leave_id,
            'content'    => $content,
            'is_audit'   => $dicts['system']['leave_audit'] ? $dicts['audit']['loading'] : $dicts['audit']['pass'],
            'ip_address' => getClientIp(),
        ]);

        // 记录操作日志
        Parent::saveOperateRecord([
            'action' => 'Leave/publish',
            'params' => $input,
            'text'   => $leave_id ? '回复成功' : '留言成功',
        ]);

        $result['list']['response']      = [];
        $result['list']['show_response'] = true;
        $result['list']['user']          = DB::table('users')->where('id', $this->getCurrentId())->first();
        return responseResult(true, $result, $leave_id ? '回复成功' : '留言成功');
    }

    /**
     * 获取最新的10条留言
     * @return Array
     */
    public function getNewLeaveList()
    {
        $dicts  = $this->getRedisDictLists(['audit' => ['pass']]);
        $result['list'] = Leave::where('is_audit', $dicts['audit']['pass'])->where('status', 1)->where('parent_id', 0)->orderBy('created_at', 'desc')->limit(10)->with('user')->get();

        return responseResult(true, $result);
    }
}
