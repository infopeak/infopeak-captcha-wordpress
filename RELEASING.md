# Releasing to WordPress.org

The plugin is published at https://wordpress.org/plugins/infopeak-captcha/.

WordPress.org distributes plugins over Subversion, not Git. This repository is the source
of truth for the code; the SVN repository is only a publishing channel.

## The two checkouts

Keep them as siblings, never nested:

| Checkout | Role |
|---|---|
| This Git repository | Source of truth. All edits happen here. |
| `svn checkout https://plugins.svn.wordpress.org/infopeak-captcha/` | Publishing channel only. Files are copied in and committed. |

An SVN working copy carries its own `.svn` metadata directory, and nesting it inside the Git
repository would leave the plugin source in two places to keep in sync. The order is always:
**edit in Git, copy to SVN, commit in SVN.**

## What WordPress.org actually reads

The SVN repository has three directories:

```
trunk/         development version
tags/1.0.0/    the released version
assets/        icons, banners, screenshots (belongs in neither trunk nor tags)
```

The `Stable tag:` line in `readme.txt` tells WordPress.org which tag directory is the released
version. Those are the files users download, and that directory's `readme.txt` is what the
plugin page displays.

This is the easiest thing to get wrong: **editing only `trunk/` changes nothing publicly.**
Update trunk as well so the two do not drift, but the tag directory is the one that counts.

## Readme-only change

The common case is bumping `Tested up to` after a WordPress release. No code change, no new version.

1. Edit `infopeak-captcha/readme.txt` in this repository and commit.
2. Copy that file into **both** `trunk/` and the current `tags/<version>/` in the SVN working copy.
3. Review what is about to be published:

```bash
svn status
```

```bash
svn diff
```

4. Commit:

```bash
svn commit -m "Bump tested up to 7.1"
```

Do not bump the version number for a readme-only change. Users get no update notification,
which is the intent.

## New version with code changes

The version number has to match in four places, and a new tag directory is needed.

1. Make the code changes in this repository.
2. Bump the version in all four places:
   - `infopeak-captcha/infopeak-captcha.php`, the `Version:` header
   - `infopeak-captcha/infopeak-captcha.php`, the `IPKCAP_VERSION` constant
   - `infopeak-captcha/readme.txt`, the `Stable tag:` line
   - `infopeak-captcha/readme.txt`, a new entry under `== Changelog ==`
3. Commit in Git.
4. Copy the plugin files into `trunk/` in the SVN working copy.
5. Create the tag as an **SVN copy of trunk**, not a plain file copy:

```bash
svn copy trunk tags/1.0.1
```

6. Run `svn status` and `svn diff`, then commit the working copy in one go.

Older tag directories stay in place. WordPress.org uses them to roll back.

## There is no undo

An `svn commit` is live for every user the moment it lands. There is no rollback, only another
commit on top or permanently closing the plugin. Always read `svn diff` before committing.

## `svn log` inside the working copy is misleading

After a commit, `svn log` run inside the working copy usually will **not** show the new revision.
This is not an error and nothing was lost: only the committed files get the new revision number,
while the working copy root stays at the revision it was checked out at, and `svn log` resolves
against that root.

To see the real state, ask the server:

```bash
svn log -l 5 https://plugins.svn.wordpress.org/infopeak-captcha/
```

Or read the published file as it currently stands:

```bash
svn cat https://plugins.svn.wordpress.org/infopeak-captcha/tags/1.0.0/readme.txt
```

## Plugin page images

Icons, banners and screenshots live in `assets/` in SVN. The sources are in `wporg-assets/` here.

They belong in neither `trunk/` nor the tag directories. To change one, copy it into the SVN
`assets/` directory and commit from there.

Screenshot files must be named `screenshot-1.png`, `screenshot-2.png` and so on, in the same
order as the list under `== Screenshots ==` in `readme.txt`.

## Compatibility with new WordPress releases

The plugin is plain PHP: classic hooks on the login, registration, lost-password and comment
forms, plus a settings page. It enqueues no editor JavaScript, no jQuery, and does no image
processing.

In practice this means the changes WordPress usually warns plugin authors about, the block
editor, `@wordpress/components`, media handling and jQuery UI, cannot reach it. Reviewing a new
release is therefore short: check the field guide for deprecations affecting the hooks the plugin
actually uses, then bump the readme as above.

## Not done yet

WordPress.org can show a **Live Preview** button on the plugin page, letting people try the plugin
in the browser without installing anything. It requires a `blueprint.json` in the repository.
Worth adding, not urgent.
