Api Info (module for Omeka S)
=============================

[Api Info] is a module for [Omeka S] that gives access to some infos about Omeka
sites and resources, that are not available in the default api.

This module is mainly designed for external apps.


Installation
------------

See general end user documentation for [installing a module].

* From the zip

Download the last release [`ApiInfo.zip`] from the list of releases, and
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
- `/api/infos/site_data`: list of sites with full data (experimental)
- `/api/infos/settings`: list of main settings (experimental)
- `/api/infos/site_settings`: list of site settings (experimental)
- `/api/infos/ids?types[]=items`: list of all ids of specified types
- `/api/infos/user`: metadata of the current user (experimental)
- `/api/infos/translations?locale=fr`: all translations for the specified language (experimental)

The response is for all sites by default. Add argument `site_id={##}` or `site_slug={slug}`
to get data for a site. The response supports the api keys, so rights are checked.

Specific data can be added via a listener on `api.infos.resources`.

**Note**: the infos are available only through the api controller. To get them
via `api()`, use à standard api search and use `getTotalResults()` on the
response. For media inside a site, the search query should use `items_site_id`,
since the argument `site_id` has another meaning currently inside the core. For
files, use a standard media query with `has_original=1`, `has_thumbnails=1` and
`items_site_id`.


TODO
----

- Make the infos available directly by the internal api.


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

This plugin is published under the [CeCILL v2.1] licence, compatible with
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

* Copyright Daniel Berthereau, 2019 (see [Daniel-KM] on GitHub)


[Api Info]: https://github.com/biblibre/Omeka-S-module-ApiInfo
[Omeka S]: https://www.omeka.org/s
[`ApiInfo.zip`]: https://github.com/biblibre/Omeka-S-module-ApiInfo/releases
[module issues]: https://github.com/biblibre/Omeka-S-module-ApiInfo/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
