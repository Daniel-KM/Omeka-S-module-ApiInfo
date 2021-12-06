Api Info (module for Omeka S)
=============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Api Info] is a module for [Omeka S] that gives access to some infos about Omeka
sites and resources, that are not available in the default api.

This module is mainly designed for external apps.

This module is compatible with Omeka v3, but keep the old workarounds (totals,
thumbnail urls) for the near future.


Installation
------------

See general end user documentation for [installing a module].

* From the zip

Download the last release [ApiInfo.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `ApiInfo`.


Quick start
-----------

Data are available in `/api/infos/{name}` via a standard request.

Available infos:
- `/api/infos`: all available infos on resources and files
- `/api/infos/{api_id}`: total of any registered resource
  - `/api/infos/items`: total items
  - `/api/infos/media`: total media
  - `/api/infos/item_sets`: total item sets
  - `/api/infos/resources`: total resources
  - `/api/infos/sites`: total sites and pages
  - `/api/infos/files`: total files and file sizes
- `/api/infos/{api_id}?append=xx` or `/api/infos/{api_id}?append[]=xx&append[]=yy`
  with `urls`, `sites`, `objects`, `subjects`, `object_ids`, `subject_ids`,
  `owner_name`: append some infos for resources (items, media, item sets, and
  annotations)
- `/api/infos/site_data`: list of sites with full data (experimental)
- `/api/infos/settings`: list of main settings (experimental)
- `/api/infos/site_settings`: list of site settings (experimental)
- `/api/infos/ids?types[]=items`: list of all ids of specified types. A standard
  query can be used to limit output. Two specific query parameters are available:
  - `rank=xxx`, where `xxx` is the resource id, allows to get only the specified
  resource with the rank as key.
  - `prevnext=xxx`, where `xxx` is the resource id, allows to get the previous
  resource id, if any, the current one (the one that is requested), and the next
  one, if any.
  Of course, rank and previous/next values have meaning only for one resource
  type, so it is useless to ask multiple types.
- `/api/infos/items?output=xxx`: formatted output for `datatables`, `by_itemset`
  or `tree`
  - datatables: [datatables] is a js library to display data as a paginated and
    searchable table.
  - For tree, two modes are available. Either the query return the root id(s),
    either it returns all the ids of the tree.
    - query that returns only root id(s): the simplest query is to specify ids
      with `id=xx` or `id[]=xx`, or any other query. The tree is built with
      "dcterms:hasPart" by default. The term can be changed with argument `tree_child`.
      When the root uses a different term than other levels, for example with a
      skos thesaurus that uses `skos:hasTopConcept` and `skos:narrower`, the
      argument `tree_base` can be used.
    - query that returns all ids: the same arguments should be used (`tree_child`
      and `tree_base`), plus `tree_parent`. This last argument is required,
      because there is no default, so without it, the tree is built as in the
      first case and all ids are a root.
    For example, for a thesaurus built with the module [Thesaurus], you can use
    (case 1):
    https://example.org/api/infos/items?id=###&tree_child_base=skos:hasTopConcept&tree_child=skos:narrower&output=tree
    To build a tree from an item set, you can use (case 2):
    https://example.org/api/infos/items?item_set_id=###&tree_parent=dcterms:isPartOf&output=tree
    Of course, in this second case, you can complete the query with `property[0][joiner]=and&property[0][type]=nex&property[0][property]=dcterms:isPartOf` directly in order to go back to the first case.
    Furthermore, the default name (or the level) is `bibo:shortTitle` and the
    default title is the default title of the resource. They can be changed with
    args `tree_name` and `tree_title`. When there are multiple roots, the name
    of the root can be specified with the first item set in the query.
- `/api/infos/user`: metadata of the current user (experimental)
- `/api/infos/translations?locale=fr`: all translations for the specified
  language (experimental)
- `/api/infos/coins` (requires module [Coins]): Allows to get the COinS for the
  items specified with a standard query.
- `/api/infos/mappings` (requires module [Mapping]): all mappings for any item
  query. If `block_id` is set, the params and query from the block will be used.
- `/api/infos/references` (requires module [Reference]): Allows to get totals or
  all references for properties. To get them, use a standard query and append
  `option` and `metadata`, if needed. For example:
  `/api/infos/references?property[0][property]=dcterms:title&property[0][type]=in&property[0][text]=my-text&option[filters][languages]=fra`
  allows to get all totals for the specified text for each property.
  If you add `&metadata[subjects]=dcterms:subject`, you will have the list of subjects
  for the query. For more info about argueents, see [Reference].

**Important**:
The response is for all sites by default. Add argument `site_id={##}` or `site_slug={slug}`
to get data for a site. The response supports the api keys, so rights are checked.

Specific data can be added via a listener on `api.infos.resources`.

**Notes**
- the infos are available only through the api controller. To get them via `api()`,
  use a standard api search and use `getTotalResults()` on the response.
- For media inside a site, the search query should use `items_site_id`, since
  the argument `site_id` has another meaning currently inside the core.
- For  files, use a standard media query with `has_original=1`, `has_thumbnails=1`
  and `items_site_id`.

Other available infos:
- terms and labels appended in resources templates and translated with `append[]=all`
  The argument `append` can be `term`, `label`, `comment` or `all`. Locale can
  be used too: `locale=fr`.
- filter for media: `has_original=1`, `has_thumbnails=1`
- list of files (urls) directly from the item data: append the arg `append=urls`.
- list of sites (ids) directly from the item data: append the arg `append=sites`
  (deprecated since version Omeka 3.0, because available in standard api).
- list of files and sites (ids) directly from the item data: append the arg `append[]=urls&append[]=sites`.
- html of page content and html of blocks for site pages: append the arg `append[]=html&append[]=blocks`.

Note about the display of site page html: only pages of the main site are
available. To render blocks with links, the site slug should be added in the
config (file "config/local.config.php"):

```php
    'router' => [
        'routes' => [
            'site' => [
                'options' => [
                    'defaults' => [
                        // Required by module ApiInfo to get html of site pages.
                        // Set the slug of the main site.
                        'site-slug' => 'my-default-site-for-api',
                    ],
                ],
            ],
        ],
    ],
```


TODO
----

- [ ] Make the infos available directly by the internal api (and deprecate ones included in Omeka v3).
- [ ] Allow to render html of any page.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page.


License
-------

This plugin is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2019-2021 (see [Daniel-KM] on GitLab)


[Api Info]: https://gitlab.com/Daniel-KM/Omeka-S-module-ApiInfo
[Omeka S]: https://www.omeka.org/s
[ApiInfo.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-ApiInfo/releases
[Coins]: https://gitlab.com/Daniel-KM/Omeka-S-module-Coins
[Mapping]: https://gitlab.com/omeka-s-modules/Mapping
[Reference]: https://gitlab.com/Daniel-KM/Omeka-S-module-Reference
[Thesaurus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus
[datatables]: https://editor.datatables.net
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-ApiInfo/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
