# ExtendedPaginator plugin for CakePHP

Simple plugin to extend CakePHP 3 Paginator Component, with customized and additionnals queryParams.

## Installation

You can install this plugin into your CakePHP application using [composer](http://getcomposer.org).

The recommended way to install composer packages is:

```
composer require irongomme/ExtendedPaginator
```

Then load it in your app:

```
./bin/cake plugin load ExtendedPaginator
```

And configure it in your controller:

```
public function initialize()
{
    parent::initialize();

    $this->loadComponent('Paginator', [
        'className' => 'ExtendedPaginator.ExtendedPaginator'
    ]);
}
```

## available options

### Fields choice for paginated model

  - queryParam = fields
  - values = comma separated fields

Ex: http://myapp/articles?limit=100&fields=title,content

Will output:

```
{
    results: [
        {
            id: 1,
            title: "Lorem Ipsum",
            content: "Lorem Ipsum Lorem Ipsum Lorem Ipsum ..."
        },
        {
            ...
        }
    ]
}
```

### Unique sorting and multiple sorting with single queryParam

  - queryParam = sort
  - values = comma separated fields, prefix with - for desc

Ex: http://myapp/articles?limit=100&sort=title,-author_id

Will sort by title ascending, then by author_id descending.

### Contain associated models

  - queryParam = contain
  - values = comma separated models, with optionnal fields selection in it, by collapsing [field1,field2,...] to model name

Ex: http://myapp/articles?limit=100&contain=authors[firstname,lastname]
Ex: http://myapp/articles?limit=100&contain=authors,category[name]

If no fields are specified, all fields will be shown.
