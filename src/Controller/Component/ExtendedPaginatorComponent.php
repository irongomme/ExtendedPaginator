<?php
namespace ExtendedPaginator\Controller\Component;

use Cake\Controller\Component\PaginatorComponent;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\QueryInterface;
use Cake\Network\Exception\BadRequestException;
use Cake\Event\Event;

/**
 * ExtendedPaginator component
 *
 * Follow Open Api Scpecification to embed pagination
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 */
class ExtendedPaginatorComponent extends PaginatorComponent
{
    /**
     * Default pagination settings.
     *
     * When calling paginate() these settings will be merged with the configuration
     * you provide.
     *
     * - `maxLimit` - The maximum limit users can choose to view. Defaults to 100
     * - `limit` - The initial number of items per page. Defaults to 20.
     * - `page` - The starting page, defaults to 1.
     * - `contain` - The associated models
     * - `whitelist` - A list of parameters users are allowed to set using request
     *   parameters. Modifying this list will allow users to have more influence
     *   over pagination, be careful with what you permit.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'page' => 1,
        'limit' => 20,
        'maxLimit' => 100,
        //'contain' => [],
        'fields' => [],
        'whitelist' => ['limit', 'sort', 'page', 'contain', 'fields']
    ];

    /**
     * Handles automatic pagination of model records.
     *
     * ### Configuring pagination
     *
     * When calling `paginate()` you can use the $settings parameter to pass in pagination settings.
     * These settings are used to build the queries made and control other pagination settings.
     *
     * If your settings contain a key with the current table's alias. The data inside that key will be used.
     * Otherwise the top level configuration will be used.
     *
     * ```
     *  $settings = [
     *    'limit' => 20,
     *    'maxLimit' => 100
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * The above settings will be used to paginate any Table. You can configure Table specific settings by
     * keying the settings with the Table alias.
     *
     * ```
     *  $settings = [
     *    'Articles' => [
     *      'limit' => 20,
     *      'maxLimit' => 100
     *    ],
     *    'Comments' => [ ... ]
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * This would allow you to have different pagination settings for `Articles` and `Comments` tables.
     *
     * ### Controlling sort fields
     *
     * By default CakePHP will automatically allow sorting on any column on the table object being
     * paginated. Often times you will want to allow sorting on either associated columns or calculated
     * fields. In these cases you will need to define a whitelist of all the columns you wish to allow
     * sorting on. You can define the whitelist in the `$settings` parameter:
     *
     * ```
     * $settings = [
     *   'Articles' => [
     *     'finder' => 'custom',
     *     'sortWhitelist' => ['title', 'author_id', 'comment_count'],
     *   ]
     * ];
     * ```
     *
     * Passing an empty array as whitelist disallows sorting altogether.
     *
     * ### Paginating with custom finders
     *
     * You can paginate with any find type defined on your table using the `finder` option.
     *
     * ```
     *  $settings = [
     *    'Articles' => [
     *      'finder' => 'popular'
     *    ]
     *  ];
     *  $results = $paginator->paginate($table, $settings);
     * ```
     *
     * Would paginate using the `find('popular')` method.
     *
     * You can also pass an already created instance of a query to this method:
     *
     * ```
     * $query = $this->Articles->find('popular')->matching('Tags', function ($q) {
     *   return $q->where(['name' => 'CakePHP'])
     * });
     * $results = $paginator->paginate($query);
     * ```
     *
     * ### Scoping Request parameters
     *
     * By using request parameter scopes you can paginate multiple queries in the same controller action:
     *
     * ```
     * $articles = $paginator->paginate($articlesQuery, ['scope' => 'articles']);
     * $tags = $paginator->paginate($tagsQuery, ['scope' => 'tags']);
     * ```
     *
     * Each of the above queries will use different query string parameter sets
     * for pagination data. An example URL paginating both results would be:
     *
     * ```
     * /dashboard?articles[page]=1&tags[page]=2
     * ```
     *
     * @param \Cake\Datasource\RepositoryInterface|\Cake\Datasource\QueryInterface $object The table or query to paginate.
     * @param array $settings The settings/configuration used for pagination.
     * @return \Cake\Datasource\ResultSetInterface Query results
     * @throws \Cake\Network\Exception\NotFoundException
     */
    public function paginate($object, array $settings = [])
    {
        $query = null;
        if ($object instanceof QueryInterface) {
            $query = $object;
            $object = $query->repository();
        }

        $alias = $object->alias();
        $options = $this->mergeOptions($alias, $settings);
        $options = $this->validateSort($object, $options);
        $options = $this->checkLimit($options);
        $options = $this->checkEmbed($object, $options);
        $options = $this->checkFields($object, $options);

        $options += ['page' => 1, 'scope' => null];
        $options['page'] = (int)$options['page'] < 1 ? 1 : (int)$options['page'];
        list($finder, $options) = $this->_extractFinder($options);

        /* @var \Cake\Datasource\RepositoryInterface $object */
        if (empty($query)) {
            $query = $object->find($finder, $options);
        } else {
            $query->applyOptions($options);
        }

        $results = $query->all();
        $numResults = count($results);
        $count = $numResults ? $query->count() : 0;

        $defaults = $this->getDefaults($alias, $settings);
        unset($defaults[0]);

        $page = $options['page'];
        $limit = $options['limit'];
        $pageCount = (int)ceil($count / $limit);
        $requestedPage = $page;
        $page = max(min($page, $pageCount), 1);
        $request = $this->_registry->getController()->request;

        $order = (array)$options['order'];
        $sortDefault = $directionDefault = false;
        if (!empty($defaults['order']) && count($defaults['order']) == 1) {
            $sortDefault = key($defaults['order']);
            $directionDefault = current($defaults['order']);
        }

        $paging = [
            'finder' => $finder,
            'page' => $page,
            'current' => $numResults,
            'count' => $count,
            'perPage' => $limit,
            'prevPage' => $page > 1,
            'nextPage' => $count > ($page * $limit),
            'pageCount' => $pageCount,
            'sort' => key($order),
            'direction' => current($order),
            'limit' => $defaults['limit'] != $limit ? $limit : null,
            'sortDefault' => $sortDefault,
            'directionDefault' => $directionDefault,
            'scope' => $options['scope'],
        ];

        if (!$request->getParam('paging')) {
            $request->params['paging'] = [];
        }
        $request->params['paging'] = [$alias => $paging] + (array)$request->getParam('paging');

        if ($requestedPage > $page) {
            throw new NotFoundException();
        }

        return $results;
    }

    /**
     * Validate that the desired sorting can be performed on the $object. Only fields or
     * virtualFields can be sorted on. The direction param will also be sanitized. Lastly
     * sort + direction keys will be converted into the model friendly order key.
     *
     * You can use the whitelist parameter to control which columns/fields are available for sorting.
     * This helps prevent users from ordering large result sets on un-indexed values.
     *
     * If you need to sort on associated columns or synthetic properties you will need to use a whitelist.
     *
     * Any columns listed in the sort whitelist will be implicitly trusted. You can use this to sort
     * on synthetic columns, or columns added in custom find operations that may not exist in the schema.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository object.
     * @param array $options The pagination options being used for this request.
     * @return array An array of options with sort + direction removed and replaced with order if possible.
     */
    public function validateSort(RepositoryInterface $object, array $options)
    {
        if (isset($options['sort'])) {
            $options['order'] = [];
            $sorts = explode(',', $options['sort']);

            foreach ($sorts as $sort) {
                $direction = 'asc';

                if (substr($sort, 0, 1) === '-') {
                    $sort = substr($sort,1);
                    $direction = 'desc';
                }

                $options['order'][$sort] = $direction;
            }
        }
        unset($options['sort']);

        if (empty($options['order'])) {
            $options['order'] = [];
        }
        if (!is_array($options['order'])) {
            return $options;
        }

        $inWhitelist = false;
        if (isset($options['sortWhitelist'])) {
            $field = key($options['order']);
            $inWhitelist = in_array($field, $options['sortWhitelist'], true);
            if (!$inWhitelist) {
                $options['order'] = [];

                return $options;
            }
        }

        $options['order'] = $this->_prefix($object, $options['order'], $inWhitelist);

        return $options;
    }

    /**
     * Check the contain parameter and ensure it's included in associated models.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository object.
     * @param array $options An array of options with a limit key to be checked.
     * @return array An array of options for pagination
     */
    public function checkEmbed(RepositoryInterface $object, array $options)
    {
        //Keep models loaded from controller
        if (empty($options['contain']) || is_array($options['contain'])) {
            return $options;
        }

        //Extract associated models and optionnal fields restrictions
        preg_match_all('/([\[].*?[\]])|(\w)+/', $options['contain'], $matches);

        //$options['contain'] = $matches[0];
        $options['contain'] = [];

        //For each match
        for ($inc = 0; $inc < count($matches[0]); $inc++) {

            $embedModel = $matches[0][$inc];
            $embedFields = [];

            //See if there is fields restrictions
            if (isset($matches[0][$inc+1])
                && preg_match('/\[.*\]/i', $matches[0][$inc+1], $embedFields)) {

                $embedFields = preg_replace('/\[(.*)?\]/i', '$1', $embedFields[0]);
                $embedFields = explode(',', $embedFields);

                $association = $object->associations();

                //Force include foreign key if not yet asked
                $embedForeignKey = $association->get($embedModel)->foreignKey();
                if (!in_array($embedForeignKey, $embedFields)) {
                    $embedFields[] = $embedForeignKey;
                }

                //Test if fields exists in embedded model
                foreach ($embedFields as $modelField) {
                    if (!$association->get($embedModel)->getTarget()->hasField($modelField)) {
                        throw new BadRequestException(
                            __('Field {0} is not in embedded model {1}',
                                [$modelField, $embedModel]
                            )
                        );
                    }
                }

                //Add contains models and fields restriction, then forward to next model
                $options['contain'][$embedModel] = ['fields' => $embedFields];
                $inc++;

            //No fields selection
            } else {
                $options['contain'][] = $embedModel;
            }

            //Contained model has to be associated with paginated model
            if (!$object->associations()->has($embedModel)) {
                throw new BadRequestException(
                    __('Model {0} is not associated with paginated model',
                        [$embedModel]
                    )
                );
            }
        }

        return $options;
    }

    /**
     * Check the fields parameter and ensure it's included in model fields.
     *
     * @param \Cake\Datasource\RepositoryInterface $object Repository object.
     * @param array $options An array of options with a limit key to be checked.
     * @return array An array of options for pagination
     */
    public function checkFields(RepositoryInterface $object, array $options)
    {
        //String to array
        $options['fields'] = !empty($options['fields'])
            ? explode(',', $options['fields'])
            : [];

        if (count($options['fields']) > 0) {

            //Force include primary key
            if (!in_array($object->primaryKey(), $options['fields'])) {
                $options['fields'][] = $object->primaryKey();
            }

            //Test each fields
            foreach ($options['fields'] as $inc => $modelField) {

                //And reject if one fails
                if (!$object->hasField($modelField)) {
                    throw new BadRequestException(
                        __('Field {0} is not in paginated model fields list',
                            [$modelField]
                        )
                    );
                }
            }
        }

        return $options;
    }
}
