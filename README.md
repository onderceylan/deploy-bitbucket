Deploy Bitbucket
=========

Deploy Bitbucket is a linux server side deployment script developed in PHP which deploys your projects right after you push to your Bitbucket repo.

  - It is **not** for shared hosts.  
  - It is **based on Bitbucket's POST Hook Management** feature so you don't need to write custom bash scripts for git hooks.
  - It's meant to be able to deploy multiple sites. So you can provide multiple branches to be deployed in multiple directories. **For example, you can configure script to deploy your *master* branch into production site and deploy *dev* branch into you development site.**
  - Configuration file and client script make it possible to **use deployer class with multiple projects** on the same server.

This project is based on BitBucket's POST Hook Management system. For those who haven't heard about it, I share a quote from [Bitbucket's POST Hook Management documentation] [1]:

> When a user makes a push against a repository, Bitbucket POSTs to the URL you provided. The body of POST request contains information about the repository where the change originated, a list of recent commits, and the user that made the push. 

Features
----

* Deploys multiple branches in same project: Perfect for separate environments *(production, pre-production, staging, development and etc.)*
* Possible to work with multiple projects/repos
* Works with **git submodules** *(For example you can add wordpress as a submodule to your project)*
* Deployments made by authorized Bitbucket users only
* Resets and clears working directory on deploy so there will be no conflict
* Logs deployment tasks (With optional POST data and server variables)

Security
-----------

Deploy Bitbucket uses PHP's exec() function to work properly. Although community do not encourage developers to use it, I think that it is a powerful tool if you use it decently. These are security protections in this project against vulnerability:

* Bitbucket's POST data is required for this script to run. The script can be called with custom POST data but it will never deploy any website unless the conditions below is matched.
    * POST data must be sent with Bitbucket's user agent and must be in the right object format. It is not much reliable but better than nothing.
    * The only variable in POST data that is used in shell scripts is branch name. **The script deploys website if branch name matches any site configured in the configuration file.**
    * Script will deploy website if user who push to the repo is authorized to deploy that branch. So you can work with a team and **authorize selected users to deploy selected branches** in server.
* Renaming client script with a GUID is suggested.


Installation
--------------

Project includes 3 files.

1. DeployBitbucket.class.php (Deployer class)
2. rename-this-with-guid.php.dist (Client script)
3. rename-this-with-same-guid-as-client-script.config.dist (Configuration file)

***
#### 1. Clone your Bitbucket project to your server. Checkout your preferred branches.
*Assuming that you are using master branch for production and dev branch for development.*

Check if git is installed on your server.
```sh
which git
```
If not, install it.
```sh
apt-get install git
```
Clone your repo to sites to be deployed on push:
```sh
cd /var/www
git clone --recursive https://{{yourusername}}@bitbucket.org/{{yourusername}}/{{your-repo}}.git your-repo-prod
cd your-repo-prod
git checkout master
```

```sh
cd /var/www
git clone --recursive https://{{yourusername}}@bitbucket.org/{{yourusername}}/{{your-repo}}.git your-repo-dev
cd your-repo-dev
git checkout dev
```
***
#### 2. Clone 'Deploy Bitbucket' to your server. Rename client script and config file with a [guid generator], copy them to your project repo.
*Note that your config file and client script must have same name and must be placed in same (web accessible) folder.*

```sh
cd ~
git clone https://github.com/onderceylan/deploy-bitbucket.git
cd deploy-bitbucket
cp rename-this-with-guid.php.dist /var/www/your-repo-dev/deploy/{{your-to-be-generated-guid}}.php
cp rename-this-with-same-guid-as-client-script.config.dist /var/www/your-repo-dev/deploy/{{your-to-be-generated-guid}}.config
```
***
#### 3. Change deployer class path in you client script.

```sh
nano /var/www/your-repo-dev/deploy/{{your-to-be-generated-guid}}.php
```
Change
```php
<?php

require_once('/your/server/path/to/DeployBitbucket.class.php');

```
to
```php
<?php

require_once('~/deploy-bitbucket/DeployBitbucket.class.php');

```
Note that the deploy-bitbucket folder which contains deployer class is not have to be in a web accessible folder.
***
#### 4. Configure your Bitbucket account.

Browse your Bitbucket account, select your repo. On the settings page add a POST hook with your client script url.


![alt text](http://i.imgur.com/Yfr3c5U.png "Bitbucket Post Hook Setting")

***
#### 5. Configure server.

##### 5.1. Make sure of PHP's exec() command is enabled on your server. 
Look up to your php.ini file has no safe_mode enabled or exec is not defined in disable_functions property. If so, to use PHP's exec function set safe_mode=disabled and remove exec value from disable_functions list. For more information, [visit php documentation for exec() command.] [2] 
***
##### 5.2. Make sure of your php script has permissions to write log folder and to write your site folders.

First run the script below if you don't know your web user.

```php
<?php
// outputs the username that owns the running php/httpd process
// (on a system with the "whoami" executable in the path)
echo exec('whoami');
?> 
```
In my case, it's www-data.

```sh
chown www-data:syslog /var/log
chown -R www-data:www-data /var/www/your-repo-prod
chown -R www-data:www-data /var/www/your-repo-dev
```
***
##### 5.3. Secure your .git folders and .git* files.
I am using apache server and in my case I can secure them by adding those block of codes to apache2 config file.

```sh
<FilesMatch "^\.git">
    Require all denied
</FilesMatch>
<Directorymatch "^/.*/\.git/">
    Order deny,allow
    Deny from all
</Directorymatch>
```
***
##### 5.4. Add deploy folder and it's files in .gitignore in your repo since we're accessing them through web and we don't want git to clean them upon pull process.

```sh
deploy/
deploy/*
```

Configuration
--------------

This is what config file looks like:

```json
{
    "gitPath": "/usr/bin/git",
    "sites": [
        {
            "name": "Production",
            "remote": "origin",
            "branch": "master",
            "directory": "/var/www/your_production_site_folder",
            "hasSubmodules": true,
            "clearDirectoryOnDeploy": true,
            "authorizedUsers": [
                "bitbucketUserName1"
            ]
        },
        {
            "name": "Development",
            "remote": "origin",
            "branch": "dev",
            "directory": "/var/www/your_development_site_folder",
            "hasSubmodules": true,
            "clearDirectoryOnDeploy": true,
            "authorizedUsers": [
                "bitbucketUserName1",
                "bitbucketUserName2",
                "bitbucketUserName3"
            ]
        }
    ],
    "logging": {
        "enabled": true,
        "logFilePath": "/var/log/deploy_your_repo_name.log",
        "timezone": "Asia/Istanbul",
        "dateFormat": "d-m-Y H:i:s",
        "logPayloadData": true,
        "logServerRequest": false
    }
}
```

I think everything is clear there.

* Define your installed git path on the server.
* Add sites to be deployed on the branches that will be pushed.
* Set hasSubmodules to true if your project has submodules.
* clearDirectoryOnDeploy will reset tracked files and **remove untracked files** before pull if it's set to true. If not, script will only reset tracked files before pull.
* Authorize Bitbucket users and set permissions to whom could deploy to which site.
* Configure logging task.

License
----

This project is released under the MIT License. See LICENSE file for more information.

[guid generator]:http://www.guidgenerator.com/online-guid-generator.aspx
[1]:https://confluence.atlassian.com/display/BITBUCKET/POST+hook+management
[2]:http://www.php.net/manual/en/function.exec.php
