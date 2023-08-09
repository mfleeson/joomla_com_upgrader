# Joomla 3 Component Upgrade Rectors

Rector rules to easily upgrade Joomla 3 components to Joomla 4 MVC

Copyright (C) 2022  Nicholas K. Dionysopoulos

This is a modification of an amazing project by Nicholas K. Dionysopoulos (https://github.com/nikosdion/) which unfortunately was archved in May 2023.

For the last couple of months I have been tinkering with it to get it working again following major changes in Rector meaning that Nick's code failed to work. If you want to make improvements, have comments please find the repository here (https://github.com/mfleeson/joomla_com_upgrader)


This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.

## What is this all about?

This repository provides Rector rules to automatically refactor your legacy Joomla 3 component into Joomla 4+ MVC.

It does not do everything. It will definitely _not_ result in a _fully working_ Joomla 4 component. The goal of this tool is to automate the boring, repeated and soul–crushing work. It sets you off to a great start into refactoring a legacy Joomla 3 component into a new Joomla 4+ MVC modern component. I wish I had that tool when I refactored by hand 20 extensions between March 2020 and October 2021.

If you don't know much about the Joomla 4+ MVC and trying to divine how it works by reading its source code isn't your jam you may want to take a look at the [Joomla Extensions Development](https://github.com/nikosdion/joomla_extensions_development) book I'm writing. Like most of my work it's available free of charge, under an open source license, with full source code available, on a platform that fosters open collaboration.

## Sponsors welcome

Do you have a Joomla extensions development business? Are you a web agency using tons of custom components? Maybe you can sponsor this work! It will save you tons of time — in the order of dozens of hours per component.

Sponsorships will help me spend more time working on this tool, the Joomla extension developer's documentation and core Joomla code.

If you're interested hit me up at [the Contact Me page](https://www.dionysopoulos.me/contact-me.html?view=item)! You'll get my gratitude and your logo on this page.

## Requirements

* Rector 0.17
* PHP 7.2 or later; 8.1 or later with XDebug _turned off_ recommended for best performance
* Composer 2.x

Your component project must have the structure described below.

## What can this tool do for me?

**What it already does**
* Namespace all of your MVC (Model, Controller, View and Table) classes and place them into the appropriate directories.
* Refactor and namespace helper classes (e.h. ExampleHelper, ExampleHelperSomething, etc).
* Refactor and namespace HTML helper classes (e.g. JHtmlExample) into HTML services.
* Refactor and namespace custom form field classes (e.g. JFormFieldExample, JFormFieldModal_Example, etc).
* Refactor and namespace custom form rule classes (e.g. JFormRuleExample).
* Change static type hints in PHP code and docblocks.

**What I would like to add**
* ⚙️ Refactor static getInstance calls to the base model and table classes.
* ⚙️ Refactor getModel and getView calls in controllers.
* 📁 Update the XML manifest with the namespace prefix.
* 📁 Rename language files so that they do NOT have a language prefix.
* 📁 Update the XML manifest with the new language file prefixes.
* 📁 Move view templates into the new folder structure.
* 📁 Move backend and frontend XML forms to the appropriate folders.
* 📁 Replace `addfieldpath` with `addfieldprefix` in XML forms.
* ❓ Create a basic `services/provider.php` file. This is NOT a complete file, you still have to customise it!

**What it CAN NOT and WILL NOT do**
* Remove your old entry point file, possibly converting it to a custom Dispatcher. This is impossible. It requires understanding what your component does and make informed decisions on refactoring.
* Refactor your frontend SEF URL Router. It's best to read my book to figure out how to proceed manually.
* Create a custom component extension class to register Html, Category, Router, Tags etc. services. This requires knowing how your component works. 
* Refactor static getInstance calls to _descendants of_ the base model and table classes. It's not impossible, I just don't have the time to figure it out (yet?).

In short, this tool tries to do the 30% of the migration work which would have taken you 70% of the time. Instead of spending _days, or weeks,_ or repetitive, boring, error–prone, soul–crushing grind you spend less than half an hour to read this README, set up Rector and another minute or so to automate all that mind–boggling drudgery. You can instead spend these few days to read my book, learn how Joomla 4+ MVC works and convert your component faster than you thought is possible!

## How to use

Download this repository to your development platform.

Delete composer.lock

Copy your administrator/components/com_yourcomponent3_code into the admin folder/

Copy your components/com_yourcomponent3_code into the site folder.

Copy any modules into the modules folder.


Run `composer update --dev` to install the dependencies.

Edit the  `rector.php` 


The lines you need to change are:
```php
    $joomlaNamespaceMaps = [
        new JoomlaLegacyPrefixToNamespace('Helloworld', 'Acme\HelloWorld', []),
        new JoomlaLegacyPrefixToNamespace('HelloWorld', 'Acme\HelloWorld', []),
    ];
```
where `HelloWorld` is the name of your component without the `com_` prefix and `Acme\HelloWorld` is the namespace prefix you want to use for your component. It is recommended to use the convention `CompanyName\ComponentNameWithoutCom` or `CompanyName\Component\ComponentNameWithoutCom` for your namespace prefix.

**CAUTION!** Note that I added two lines here with the legacy Joomla 3 namespace being `Helloworld` in one and `HelloWorld` in another. That's because in Joomla 3 the case of the prefix of your component does not matter. `Helloworld`, `HelloWorld` and `HELLOWORLD` would work just fine. The code refactoring rules are, however, case–sensitive. As a result you need to add as many lines as you have different cases in your component.

The third argument, the empty array `[]`, is a list of class names which begin with the old prefix that you do not want to namespace. I can't think of a reason why you want to do that but I can neither claim I can think of any use case. So I added that option _just in case_ you need it.

Now you can run Rector to do _a hell of a lot_ of the refactoring necessary to convert your component to Joomla 4 MVC.

First, we tell it to collect the classes which will be renamed but without doing any changes to the files. **THIS STEP IS MANDATORY**.

```bash
php ./vendor/bin/rector --dry-run --clear-cache
```

Note: The `--dry-run` parameter prints out the changes. Now is a good time to make sure they are not wrong.

Then we can run it for real (**this step modifies the files in your project**):

```bash
php ./vendor/bin/rector --clear-cache
```

Once it has completed running you will find src folders in admin and site which contain the updated files and hopefully the correct file structure.

## How this tool came to be

There's been a discussion on Joomla's GitHub repository about how “hard” it is to convert a Joomla 3 component to the new MVC shipped with Joomla 4. Having had the experience of converting 20 extensions myself — and several more dozens of plugins and modules which came with three quarters of them — I realised it's not “hard” but two crucial things were missing: documentation and a tool to get you started.

The lack of documentation is something I lamented when I started trying to figure out how to support Joomla 4 in my own extensions. I decided to address it with my [Joomla Extensions Development](https://github.com/nikosdion/joomla_extensions_development) book.

How to get started is a pained story. Most of my own code was already namespaced (as I was using FOF for my components which since version 3, released in 2015, required namespacing the code), therefore my experience was mostly changing namespaces and converting the internals from FOF MVC to core Joomla 4 MVC. I had two components written in plain old Joomla 3 MVC and _that_ experience sucked! I totally get the people who say it's hard. It's so boring and you need to do so much work before you see any results that it feel intimidating and unapproachable.

At this point I've been using Rector for years to massage my code whenever I am changing something — albeit it's mostly been renaming classes. I looked at how to write custom Rector rules and I realised I actually understood what's going on! Apparently a summer spent 24 years ago writing my own compiler following a tutorial gave me a good background to write Rector rules today. Huh!

So, here we are. Custom Rector rules to start converting legacy Joomla 3 MVC components to Joomla 4, free of charge, because **community matters**. ☮️