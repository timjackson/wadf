WADF - Web Application Deployment Framework
=========================================================
(c)2006-2020 Tim Jackson (tim@timj.co.uk)

Introduction
------------
WADF is a simple templating and deployment system combined with some conventions to make deployment of web applications more reliable.

There are several key concepts and features:
- Abstraction of all system (deployment)-specific information including database details, file paths etc.
 - includes generation of database, hostname and other details if required
- Simple database management including setup of database server
- PEAR Installer, SVN and Git integration for dependency management
- Webserver configuration management
 - includes ability to manage a local, user-controlled "micro" webserver for development on a local workstation
- PHP-aware; supports both mod_php and CGI PHP installations

Principles
----------
- WADF is based on having a checked-out copy of the core ("end user") application from a version control system
- All other dependencies (libraries etc.) are then pulled in via the PEAR installer
- "Profiles" allow varying runtime configuration between systems (e.g. developer workstation, staging server, live server)

WADF is mostly dependent on PEAR and assumes you have a PEAR package.xml metadata file in the root of the application which describes, in particular, the dependencies of the application. See http://pear.php.net/manual/en/guide.developers.package2.php for more details.

Requirements
------------
WADF requires that you have a recent version of PEAR.
It is tested on Fedora Linux, but should probably work OK on most Linuxes and probably other UNIX variants (though the sample httpd.conf for the micro HTTP server may require adjustment).
It will not work on Windows at present.




---------------------------------------------------------




User Guide
==========


1. INSTALLING WADF
---------------------------------------------------------
WADF is available from the PEAR channel "pear.timj.co.uk" and it is recommended you install it using PEAR.

First you will need to discover the channel, like so:
```
channel-discover pear.timj.co.uk
```
and then either download and install WADF directly from the network as such:
```
pear install timj/Tools_WADF
```
or install from a downloaded tarball like so:
```
pear install Tools_WADF-x.y.z.tgz
```


2. BASIC WADF CONFIGURATION
---------------------------------------------------------
WADF is primarily a templating tool based on specifying "macros" which have values, possibly dependent on other macros and possibly overwritten by later declarations of the same macro. Read the heavily-commented wadf.conf for details, which sets up the "standard" macros, many of which are used internally by WADF for configuring its behaviour as described throughout this document. The intention is that a system-wide generic config file is used, which may then be overriden by, respectively:

- User-specific config file (~/.wadf/config)
- Specific config file for site (see the section "Additional Configuration - local_config")
- Instance-specific configuration variables (see the section "The WADF Instance File")
- Command line options

Note that the configurations (both global, user-specific and site specific) are split into sections:

- `[globals]`
- `[local]`
- `[dev]`
- `[staging]`
- `[live]`

Only `[globals]` is special.  The other section names are merely identifiers and can be anything you wish as long as they match what you intend to use as "profile" names on the various servers/machines you are going to deploy to. Although not necessary, it is suggested that you stick with the values suggested above, where:

- "local" is for deployment to a local workstation
- "dev" is for deployment to a shared development server
- "staging" is for deployment to a client-facing staging server
- "live" is for deployment to a production server


3. DEPLOYING A SITE WITH WADF
---------------------------------------------------------

### Basic Setup Requirements

- The webserver config dir (vhost_config_path) exists and is writeable
- User can execute webserver_restart_cmd (may imply sudo setup etc., depending on the configuration value)
- Apache is installed
- Database server is installed and running (if databases are used)
- All required PHP extensions (e.g. php-mysql) are installed
- User can write to /etc/hosts, if deploy_dns is set to "hosts"
- SELinux (if present) permits all relevant actions, such as permitting a micro-webserver to read deployed files


### Principles of templating

Files with macros to be substituted are named with a ".template" extension. When deploying, WADF will replace macros in these files and output to a new file, named the same but without the .template extension.

Any of the configuration options/macros are available for substitution. Additionally, you can use custom macros which are between 3 and 50 characters, named using the characters 0-9a-z_ or a subset thereof

Key "core" macros/configuration options of interest are:

- `@appref@` - app reference (see above)
- `@instance@` - ID of the deployed instance
- `@deploy_path@` - the filesystem path to the deployed client site e.g. /foo/bar/clientname/sitename. Does not have a trailing slash.

If the deployed site uses PEAR (see later), additional macros based on PEAR configuration parameters will also be available, dependent on the value of the option dep_pear_opts_as_macros. For example, the path to libraries installed via PEAR is normally contained in the option "php_dir".

Files within the site (including the vhost.conf and any source files e.g. PHP) should not reference any absolute paths except via macros.

If you want to exclude certain paths from template processing (e.g. temporary checkouts from a version control system), see the 'template_exclude_paths' option.


### Webserver configuration

In the root of your site you should have a file named according to the value of the vhost_config_template file. By default this is `vhost-@webserver_type@.conf` which resolves to "vhost-httpd.conf" for Apache HTTPD. Some useful macros when creating a template vhost config are:

- `@vhostX_name@` - the HTTP host name
- `@vhostX_interface@` - the vhost interface details which may be an IP, IP/port or multiples of these

where the 'X' in vhostX_name and vhostX_interface is an integer from 1..n depending on how many virtual hosts exist. This allows apps that have several different vhost interfaces to be supported.

e.g.
```
# vhost.conf.template
<VirtualHost @vhost1_interface@>
	ServerName @vhost1_name@
	DocumentRoot @deploy_path@/webroot
	RewriteEngine on
	RewriteRule /.* @deploy_path@/application/bootstrap_http.php
</VirtualHost>
```
Don't include any environment-specific configuration in here, e.g. logs. These should be configured separately, possibly via the vhost_config_append/vhost_config_prepend macros.


### Deployment of dependencies using PEAR

For more information about the PEAR installer and metadata format (package.xml), see http://pear.php.net/.

If the root of the deployed site contains a file called "package.xml", it is assumed to be a PEAR metadata file. Depending on the value of the dep_pear_deploy_path (see default wadf.conf), a fresh PEAR installation may be created automatically by WADF within the site. The site will then be "installed" using PEAR which will pull in any dependencies defined in the PEAR package.xml

There are a number of options to control the deployment of dependencies via PEAR; see the options beginning "dep_pear..." in wadf.conf.


### Dependency tagging

The PEAR dependency management discussed above is extremely useful, but sometimes it's desirable to make deployments even more reproducible by ensuring that the exact versions of dependencies are installed. This can be done by use of "dependency tags". A "dependency tags" file simply consists of a list of dependencies in a particular format. When deploying or re-processing a site, WADF forcibly installs the dependencies listed, after performing the normal dependency installation.

Where to find the dependency tags file is controlled by the option "dep_tags_file"; it defaults to look for a file called "dependency-tags" in the root of the deployed site.

When reprocessing a site using wadf-reprocess, you can use the option "--no-dep-tags" to ignore the dependency tags file and use the "normal" dependency mechanism.

The following types of dependencies can be deployed via dependency tags:
- PEAR packages,
- SVN dependencies and
- Git dependencies.

#### PEAR package dependencies:
These are listed in the dependency tags file in a format like this:
```
PEAR:channel.example.com/Package_Name-1.2.3
```

#### SVN dependencies:
These are listed in the dependency tags file as follows:
```
SVN:http://svn.example.com/path/to/something@1234 somedir/path
```

where 1234 is the revision number to check out and "somedir/path" is the path relative to the deployment root to check the working copy out to.
When SVN dependencies are checked out, WADF looks to see if they appear to be PEAR packages (i.e. have a package.xml in their root). If so, it installs that into the main PEAR installation for the deployment.
Deployment of SVN dependencies is more reliable if you use an SVN client version 1.5 or later, as WADF can use "peg" revisions to ensure it gets the exact version of the file specified in the dependency tags file.

#### Git dependencies:
These are listed in the dependency tags file as follows:
```
Git:http://git.example.com/path/to/repo.git somedir/path                        [1]
Git:http://git.example.com/path/to/repo.git|branch|master somedir/path          [2]
Git:http://git.example.com/path/to/repo.git|branch|master|123efb5 somedir/path  [3]
Git:http://git.example.com/path/to/repo.git|branch|1.0|123efb5 somedir/path     [4]
Git:http://git.example.com/path/to/repo.git|tag|1.0.1                           [5]
```

Lines 1 and 2 will have the same effect. After the repo URL you can specify if you want to get a branch or a tag.
You can also specify a particular commit ("123efb5" in the examples above) that will be deployed, however you can only do this for branches (a dependency for a tag that is using a specified commit will be skipped).
Note: specifying a commit will create a "detached HEAD".
As with SVN dependencies "somedir/path" is the path relative to the deployment root where the code will be placed in (git clone).


### PHP configuration

PHP configuration varies according to the environment. If you are using mod_php (that is, PHP installed as an Apache HTTPD module), the PHP directives are typically included in the Apache config, as part of the <VirtualHost ...> section, for example:
```
php_admin_flag engine on
```
However, for PHP running as a CGI, the directives are usually stored in a separate php.ini file.

WADF is aware of both types of PHP configuration and explicitly supports them via the "php_type" configuration parameter. In either case, it expects to find a file called "php.ini" in the root of the site (this will normally be pre-templated as php.ini.template). Then, when deploying a site, WADF inserts PHP directives in the appropriate place.
- If "php_type" is set to "mod_php", then it converts the directives to Apache config style and inserts them in the VirtualHost. It also converts comments from the php.ini "; comment" format to the Apache "# comment" format.
- If "php_type" is set to "cgi:/path/to/file" then it simply copies the ini file to "/path/to/file" ready to be used by the CGI interpreter.

Example php.ini:
```ini
engine = On
# when processed, 'php_dir' will be the path to the PEAR installation
include_path = .:@deploy_path@/include:@php_dir@
```
No matter whether you are running PHP as mod_php or CGI, you can also set additional PHP options on a per-profile basis. By setting the "php_config_location_extra" parameter to point to a php.ini file, the options from this ini file will be appended to the rest of the PHP options from the normal application PHP config. This can be useful, for example, to force "display_errors" to always be turned on for a development machine.


### Database configuration

For each database that the application uses, you should store the schema in the root directory, named "schemaX.sql" where X is the database number. (For the first schema, omit X - i.e. call the file simply "schema.sql").

In your code, you should use a template file (see above) and use the
following macros:

- `@dbX_type@` - database type according to the PEAR conventions
- `@dbX_host@` - database host
- `@dbX_name@` - database name
- `@dbX_user@` - database username
- `@dbX_pass@` - database password

where X is the database number (to support apps that call on multiple
databases)

e.g.
```
@db1_type@://@db1_user@@@db1_host@/@db1_name@/@db1_pass@
```

This will be replaced with the database hostname, which is selectable by the WADF client according to the profile in use. The above definitions are configured separately on the staging server, on a per-application basis.


### Kickstart (setup) script

Often, you want to run a "kickstart" script after initial deployment, to pre-populate a database with initial values or something similar (not necessarily involving a database). To support this, WADF has a "kickstart_script" option which, if set, executes the specified script after deployment.

When running a kickstart script, WADF sets some environment variables which can be useful in the script:

- "DEPLOY_INITDB" is to indicate whether the database was re-deployed. It will be set to "1" if the database was re-deployed and "0" otherwise. This can be useful for the script to decide whether it needs to re-initialise values in a database or not.
- "DEPLOY_VERBOSITY" indicates the verbosity level of WADF's output, where increasing numbers mean an increasing level of verbosity. Currently this will usually be "0", but will be "1" if WADF was called with a "-v" option. In the future, higher numbers may be used to indicate increasing levels of verbosity.


4. ADDITIONAL CONFIGURATION
---------------------------------------------------------
Although the default configuration options are probably sufficient for deployment of sites for development purposes (e.g. using auto-generated usernames and passwords), more complex configuration is required to manage the demands of a multi-stage environment where you may have (for example) development, staging and live environments that differ.


### local_config

The local_config option specifies the location of a file where configuration directives pertaining to the specific application (rather than applications in general) can be put. By default this is in @deploy_path@/wadf.conf. The format of this file is exactly the same as other WADF config files and typically this will include several profile sections, containing environment-specific configs (such as database hostnames, virtual host hostnames etc.). It may also contain configuration variables which are specific to that application and which are not standard WADF variables. For example:

```ini
[globals]
; This is an option which is specific to this application and not a WADF
; standard variable
my_config_option = foo

[live]
vhost1_name = www.example.com
db1_host = @underscore:appref@.db.example.com
my_config_option = bar

[staging]
vhost1_name = staging.example.com
```

### Sensitive configuration parameters

There may be some configuration parameters which you regard as sensitive and may not want to store in the version control system along with the main application, for reasons of security or privacy. For example, database passwords for your live servers. In that case, you may want the person deploying the application to have to enter them.

To achieve this, you can specify "%%" as the value of any configuration parameter. Then, when wadf-deploy is run, it will prompt for the value of this option. For example:
```ini
[live]
db1_pass = %%
```
In the above example, when deploying to the live server, WADF will prompt as follows:
```
"Please enter the value for the configuration option 'db1_pass':"
```
The value stated will be stored locally in the instance file (see next section). Then, next time a wadf-reprocess is run *on that copy*, the value that was entered will be automatically substituted. If you want to edit the value, see the instructions in the next section.

Optionally, you can specify notes following the '%%'. These do not need to be quoted or escaped, but should not include double quotes. For example:
```ini
[live]
admin_email_address = %%This is auto-generated; ask the Deployment Team after server set up
```
These will be shown when deployment is terminated, like so:
```
"Please enter the value for the config option 'db1_pass'
Notes: This is auto-generated; ask the Deployment Team after server set up"
```


### Marking macros as "to do"

Sometimes, it is desirable to be able to mark macros as "to do" (particularly when checking into a version control system), meaning that their value *should* be filled in before deployment (unlike macros marked as "%%"); however, at the present time their correct/final value is unknown. The special value of "**" can be used to indicate this. A good example of when this might be useful would be when configuring some custom parameter for a site (e.g. the administrator's e-mail address) and it is desirable to use a different setting for different profiles (e.g. during development and when launching on a live system). In this case, a configuration similar to the following could be used in a local wadf.conf (assuming that a profile called "live" is used on live servers):
```ini
[globals]
admin_email_address = me@example.com

[live]
admin_email_address = **
```
By doing this, when it comes to deploying the live system, if nobody has yet filled in the correct value for the live macro, WADF will terminate deployment and give the following error:
```
"The macro 'admin_email_address' is set to '**', indicating that it needs to be configured."
```
Optionally, you can specify notes following the '**'. These do not need to be quoted or escaped, but should not include double quotes. For example:
```ini
[live]
admin_email_address = **Awaiting confirmation from John of what this value should be.
```
These will be shown when deployment is terminated, like so:
```
"The macro 'admin_email_address' is set to '**', indicating that it needs to be configured.
Notes: Awaiting confirmation from John of what this value should be."
```


5. THE WADF INSTANCE FILE
---------------------------------------------------------
In order to know that a particular directory is a WADF-deployed application, WADF creates a special file called .wadf-instance in the deployed directory. This file is a simple newline-separated text file which has the following format:

- The first line contains the application reference of the deployed application
- Second and subsequent lines contain WADF configuration specific to that particular instance (typically, those entered via the "%%" configuration value construct), in the format "key = value".

No comments are permitted in the file. There are no profile sections in the file because, by definition, the file is specific to the current deployed instance, whatever that may be.


6. THE WADF MICRO HTTP SERVER
---------------------------------------------------------
WADF can start up local instances of Apache running as the current user - meaning that a complete development environment that closely simulates a live environment can be set up without requiring special privileges.

You do not have to use the WADF micro HTTP server at all, if you don't want to. If you do, it is controlled via the "wadf-httpd" script which is described fully in the next section, "Command Line Usage". You will need to set the following WADF options:
```ini
webserver_restart_cmd = wadf-httpd reloadstart
vhost_config_path = /home/@user@/.wadf/vhosts (NB this path is currently "special" - do not change it)
```
When first running the wadf-httpd server, a sample config is set up in ~/.wadf/httpd.conf. You can then amend this file as you see fit.

After starting the WADF HTTP server, a number of additional files will be generated in ~/.wadf by default (that is, assuming you don't change the default WADF Apache config):

- httpd.error_log - this is the Apache output error log
 - When restarting WADF, this is cleared and the old log moved to httpd.error_log.old
- httpd.lock - this is just a lock file for the server which you can ignore
- httpd.pid - a file containing the running process ID of the server. Leave this alone as it is used by the wadf-httpd script.
- default_error_page.html - a file with a list of the deployed applications, shown if you access the local WADF HTTP server over an unconfigured interface
- vhosts/00-default.html - the virtual host to trigger the default error page


7. COMMAND LINE USAGE
---------------------------------------------------------
WADF has a number of command line tools which are described below. In addition to the functionality below, most also support an additional command line switch "-V" which, if used on its own, provides version and licensing information.


### wadf-deploy [-d `<macro name=macro value>` [-d ...]] [-r `<revision>`] `<appref>` [`<version>`]

This performs a fresh deployment of application <appref> from the version control system.

Optionally, it may be passed the following parameters:
* `<version>`: The version control revision to retrieve. This should be in the format:
	- `tag/XXX`:    Use the version of the software identified with tag "XXX". "tag/LATEST" means use the most recent tag.
	- `branch/XXX`: Use the HEAD of the branch called "XXX"
	- `trunk`:      Use the software trunk (this is the default for deploying)
* -d `<macro name=macro value>`: A macro override
* -r `<revision>`:   The specific revision number to check out. Only applicable where <version> is set to "trunk" or "branch".


### wadf-reprocess

Reprocess the current working directory as a WADF site (i.e. remake templates).
This does not automatically re-initialise the database or re-run the kickstart script. The user is prompted about the kickstart script, but not about redeploying the database as this is generally a less-commonly used (and destructive) option. To redeploy the database, pass the "--db" option. (A confirmation prompt is still shown).

Optionally, wadf-reprocess can be passed parameters to switch the working copy to a different version from the version control system. These take the following form:

- `tag/XXX`:    Use the version of the software identified with tag "XXX". "tag/LATEST" means use the most recent tag.
- `branch/XXX`: Use the HEAD of the branch called "XXX"
- `trunk`:      Use the software trunk (this is the default for deploying)

Time-saving options:
- You can skip the prompt for re-running the kickstart process with the "--no-kickstart" option. However, it would be better to fix the kickstart script so that it can run (safely) in all circumstances! (See the "Kickstart (setup) script" section for advice about this, in particular the DEPLOY_INITDB variable.)
- Dependency deployment can be skipped with "--no-deps", and use of dependency tags can be avoided with "--no-dep-tags".
- You can redeploy the database only with "--db-only".
- You can process templates only with "--templates-only" (shortcut for "--no-deps --no-kickstart")


### wadf-list-macros [-a] [`<appref>`]

Lists the macro names and values associated with the deployed instance of `<appref>`. If no `<appref>` is supplied, it looks in the current directory for a deployed instance.

By default, it shows only macros which are actually used in the deployed instance. If the "-a" option is passed, it shows all known macros.


### wadf-clean

Remove files generated from templates from the working directory


### wadf-httpd `<action>`

For workstation-based development, controls the Apache HTTPD webserver running as the current user.

`<action>` is one of the following:
- `start`: Start the webserver
- `stop`: Stop the webserver
- `restart`: Stop, then start the webserver
- `condrestart`: Restart the webserver if it is already running
- `reload` or `graceful`: Gracefully restart the webserver if it is already running
- `reloadstart`: Gracefully restart the webserver if it is already running, otherwise start
- `status`: Show status information about whether the webserver is running and if so, what applications are deployed
- `configtest`: Check the syntax of the Apache configuration, incorporating the various virtual hosts

The output of "wadf-httpd status" looks something like the following:

```
*******************************************
WADF HTTPD instance is running (pid 1234)
Listening on port 10080; configured for the following applications:

 someapp: (/path/to/someapp) ver=DEVTR:1234
   http://someapp.mypc.example.com:10080

 otherapp: (/path/to/otherap) ver=DEVTR:4870
   http://otherapp.mypc.example.com:10080
*******************************************
```

The "ver=" output shows which version was deployed the last time that "wadf-reprocess" was run. This is in the format described in the section "Version identifiers".


8. VERSION IDENTIFIERS
---------------------------------------------------------
A version identifier is available to uniquely identify the version of the end-application (client application) checked out from the version control system. This is available in the 'deploy_version' macro during template processing. It is in the following format:

- `DEVTR:[revision]`:               trunk or master branch
- `DEVBR/[branch name]:[revision]`: branch
- `[tag name]`:                     specific tag

where "[revision]" is the revision number


9. CRONTAB SUPPORT
---------------------------------------------------------
By creating a file called "crontab" in the root of the deployed application (in standard crontab format), WADF can install standard cronjobs for the current user account. It places special metadata markers in the installed crontab so that if further redeployments are done, the crontab entries are not duplicated.


10. GOTCHAS
---------------------------------------------------------
If you are letting WADF generate a "clean" PEAR installation when you deploy an application, then if you need to run PEAR manually for any reason, you should be sure to use the PEAR "binary" from the "clean" install

i.e. DON'T do this:
```
pear -c /path/to/myapp/pear_local/.pearrc [something]
```
Instead, do this:
```
/path/to/myapp/pear_local/pear [something]
```
Otherwise, "odd" stuff may happen with config variables.


11. FAQ
---------------------------------------------------------
**Q: What about SSL?**  
A: SSL is a hosting-environment-specific (not application-specific) configuration so should either be done via an SSL terminator or via vhost_config_append in the 'live' profile

**Q: What about redirects (e.g. to force a certain page to redirect to a secure version) based on whether the site is being accessed over SSL?**  
A: Use a local_config file (see section "Additional Configuration") to set a custom variable (e.g. "enable_ssl") on a per-profile basis. Set it to "0" in the "globals" profile and then for the profile(s) that you want it enabled (e.g. "live"), set it to "1". Then, you may have a page something like this:

checkout.php.template:
```php
<?php
if ('@enable_ssl@' == '1') {
	// N.B. the $_SERVER['HTTPS'] variable is not fully standardised and will
	// depend on your hosting environment. Here we assume that it is present and
	// set to "1" if the current page is being served over a secure connection.
	if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 1) {

		// This is not the fully correct way to determine the current HTTP host
		// but it illustrates the point here.
		$host = $_SERVER['HTTP_HOST'];

		header("Location: https://$host/checkout.php");
	}
}
```

