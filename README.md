# BurnBot
An IRC bot designed and built completely in PHP

# Why PHP?
Initially I wanted to prove someone wrong.  After a conversation in IRC, a chatter essentially said that PHP couldn't make a good bot.  I took that challenge on and, after a couple of weeks, I had gon from prototype that had hard-coded command listeners and a very basic message parser to a command and permissions structure.  Now my reasons for PHP are a little more reasonable.  I have looked into Node.JS quite a bit and wanted to make an attempt at making V3 in Node, but have chose not to due to no straight forward class safety and no good way of protecting methods or properties.  I would like to have the bot in somethin async like node thought, so I may build a version with threading and thread safety and see how the performance is.


# Pros/Cons
## Pros
* Full permissions system having independent layers and an override layer for cases where OP/etc. can not be obtained (Requires log access)
* Complete logging system that is exposed to modules to allow for debugging output (All types of output are independent and can be toggled indpendently)
* Full message system allowing for messages to be sent to the channel or any other target, at any time, in special cases (like action text) and with a queueing system that allows for messages to be sent with a rate-limit in mind.
* Full command system allowing for custom message paring and/or custom actions
* Database settings and command storage
* Has several modules that show some of what modules are capable of
* Extremely flexible 

## Cons
* Modules have to be written in PHP (For now)
* Only supports 1 connection per instance, so you may not use this to bridge channels
* Only currently supports IRC-like servers

# Basics
* !help and !listcom are your friends
* !help can provide information and help text for a command if you provide a command trigger after !help
* The bot will outright ignore command requests when you do not have permissions.  So if the bot diesn't do anything, check you have the permission to run the command.
* Permission layers have no inheritance until you get to Operator and Override.  Operator inheritas all commands that are not protected and Override inherits all commands.  These layers can run the commands they inherit.
* Modules can employ their own rules for commands, so be sure to check the module help if you encounter issues with commands from modules

# Modules
## Channel
* ### Description
