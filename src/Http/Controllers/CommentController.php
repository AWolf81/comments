<?php namespace Laravelista\Comments\Http\Controllers;

use Illuminate\Http\Request;
use Validator;
use Laravelista\Comments\Comments\Comment;
use League\Fractal\Manager;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use Laravelista\Syndra\Syndra;
use Laravelista\Comments\Comments\CommentTransformer;
use Laravelista\Comments\Comments\UserTransformer;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use Illuminate\Routing\Controller as BaseController;
use Laravelista\Comments\Events\CommentWasPosted;
use Laravelista\Comments\Events\CommentWasUpdated;
use Laravelista\Comments\Events\CommentWasDeleted;

class CommentController extends BaseController
{
    protected $fractal;
    protected $syndra;
    protected $_commentModel;

    public function __construct(Manager $fractal, Syndra $syndra, $commentModel = null)
    {
        $this->fractal = $fractal;
        $this->syndra = $syndra;
        $this->_commentModel = $commentModel ?? Comment::class;

        if (config('comments.remove_data_property')){
            $this->fractal->setSerializer(new ArraySerializer());
        }

        /**
         * Comments Index can be viewed as guest.
         */
        $this->middleware('auth.comments', ['except' => ['index']]);
    }

    /**
     * Prepares valid content from
     * config for validation.
     *
     * @return string
     */
    protected function getValidContentTypeString()
    {
        return implode(',', config('comments.content'));
    }

    /**
     * Basic rules for validation on every request.
     *
     * @return array
     */
    protected function getBasicRules()
    {
        return [
            'content_type' => 'required|string|in:' . $this->getValidContentTypeString(),
            'content_id' => 'required|int|min:1'
        ];
    }

    /**
     * Returns a validator instance with basic and additional rules.
     *
     * @param  array  $data             Most likely $request->all().
     * @param  array $additional_rules Request specific rules.
     * @return Validator
     */
    protected function baseValidate(array $data, array $additional_rules = [])
    {
        return Validator::make($data,
            array_merge($this->getBasicRules(), $additional_rules)
        );
    }

    /**
     * Based on content and id, it returns an eloquent model.
     *
     * @param  string $content Eg. App\user
     * @param  int    $id      Model id
     * @return Illuminate\Database\Eloquent\Model
     */
    protected function getModel($content, $id)
    {
        return $content::findOrFail($id);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request, $transformers = null)
    {
        $transformers['comment'] = $transformers['comment'] ?? CommentTransformer::class;
        $transformers['user'] = $transformers['user'] ?? UserTransformer::class;

        $validator = $this->baseValidate($request->all());

        if($validator->fails()) {
            return $this->syndra->respondValidationError(
                $validator->errors()->getMessages()
            );
        }

        $model = $this->getModel(
            $request->get('content_type'),
            $request->get('content_id')
        );

        // $paginator = $model->comments()->paginate(5);
        // $resource = new Collection($paginator,
        //     new $transformers['comment']($transformers['user']));

        $resource = new Collection($model->comments,
            new $transformers['comment']($transformers['user']));

        // pagination (if page passed)
        // $page = $request->input('page');
        // if ($page !== null) {
        //     // $queryParams = array_diff_key($_GET, array_flip(['page']));
        //     // $modelClass = $request->get('content_type');
        //     // $paginator->appends(['page' => $page]);
        //     $queryParams = array_diff_key($_GET, array_flip(['page']));
        //     $paginator->appends($queryParams);
        //
        //     $paginatorAdapter = new IlluminatePaginatorAdapter($paginator);
        //     $resource->setPaginator($paginatorAdapter);
        // }


        /**
         * If user is logged in, add his data to meta.
         */
        if(auth()->check())
        {
            $user = new Item(auth()->user(), new $transformers['user']);
            $data = $this->fractal->createData($user)->toArray();

            $resource->setMetaValue('user', $data);
        }

        $data = $this->fractal->createData($resource)->toArray();

        return $this->syndra->respond($data);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $transformers = null)
    {
        $transformers['comment'] = $transformers['comment'] ?? CommentTransformer::class;
        $transformers['user'] = $transformers['user'] ?? UserTransformer::class;

        $validator = $this->baseValidate($request->all(), [
            'comment' => 'required|string'
        ]);

        if($validator->fails()) {
            return $this->syndra->respondValidationError(
                $validator->errors()->getMessages()
            );
        }

        $model = $this->getModel(
            $request->get('content_type'),
            $request->get('content_id')
        );

        $comment = new $this->_commentModel; //Comment;
        $comment->user()->associate(auth()->user());
        $comment->content()->associate($model);
        $comment->comment = $request->get('comment');
        $comment->save();

        event(new CommentWasPosted($comment));

        $transformedComment = new Item($comment, new $transformers['comment']($transformers['user']));
        // return $this->syndra->respondCreated();
        return $this->syndra
            ->respond([
                // todo: add location header to the created comment?
                'comment' => $this->fractal
                    ->createData($transformedComment)->toArray(),
                'message' => 'created'])
            ->setStatusCode(201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator =  Validator::make($request->all(), [
            'comment' => 'required|string'
        ]);

        if($validator->fails()) {
            return $this->syndra->respondValidationError(
                $validator->errors()->getMessages()
            );
        }

        $comment = $this->_commentModel::findOrFail($id);

        // If the user is not the comment owner.
        if(auth()->user()->id != $comment->user->id) {
            return $this->syndra->respondForbidden();
        }

        $comment->comment = $request->get('comment');
        $comment->save();

        event(new CommentWasUpdated($comment));

        return $this->syndra->respondUpdated();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $comment = $this->_commentModel::findOrFail($id);

        // If the user is not the comment owner.
        if(auth()->user()->id != $comment->user->id) {
            return $this->syndra->respondForbidden();
        }

        // first emit event to avoid model not found error
        event(new CommentWasDeleted($comment));

        $comment->delete();

        return $this->syndra->respondOk();
    }
}
