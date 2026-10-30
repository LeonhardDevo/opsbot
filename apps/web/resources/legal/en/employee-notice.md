---
title: 'Employee notice'
version: '1.0'
effective_date: '2026-10-22'
summary_of_changes: 'Initial version.'
requires_acceptance: false
---

<!-- template-notice -->

This notice is for employees of companies that use {{product.name}}. Your employer (the "company") uses {{product.name}} as an assistant in Telegram: it answers work questions from the company's approved documents, helps you report incidents and reminds you about tasks. The company decides why and how your data is processed and is the controller of that data. {{company.name}} operates the Service on the company's behalf as a processor under a Data Processing Agreement.

## 1. What is recorded

When you use the company bot, the following is recorded in the company workspace:

- **Your Telegram identity**: your Telegram user ID, chat ID, username (if you have one) and the language of your Telegram app. They are needed to recognise you and to send you answers and notifications.
- **Your questions and the answers**: the text of each question, the answer the assistant gave, the time, the documents cited as sources, whether the answer was refused, and your role and departments at that moment.
- **Questions passed to a manager**: if the assistant could not answer, or you used /ask_manager, the question is placed in the managers' queue together with the manager's answer.
- **Incidents and tasks**: incidents you report (category, description, the client or property you mention) and tasks assigned to you, including their status history and reminders.
- **Opening of source documents**: when you open a source link from an answer, the fact that you opened that document version.

The bot does not read other chats, does not record voice messages or files you have not sent to it, and does not track your location.

## 2. Who can see it

- **Company owners and administrators** can see all questions, answers, incidents and tasks in the workspace.
- **Managers** can see the questions, incidents and tasks of the departments they manage.
- **Other employees** cannot see your questions or the answers you received.
- **{{company.name}}** staff access workspace data only when needed to operate and support the Service, under the Data Processing Agreement.

## 3. Artificial intelligence

Answers are produced automatically from the company's approved documents and always cite their sources. They can be incomplete or wrong. If an answer looks wrong or the situation is risky, check the source document and ask your manager. A manager's answer is marked as an answer from a manager, not from the regulations.

The company decides whether external AI providers may be used. If they are not allowed, questions are answered only by built-in models inside the Service. If they are allowed, the question and the relevant excerpts of company documents are sent to the selected AI provider with personal identifiers (e-mail addresses, phone numbers, card and account numbers) masked. AI providers may not use this data to train their models. The current list of providers is published at {{product.url}}/legal/subprocessors.

## 4. How long data is kept

| Data | Retention |
| --- | --- |
| Questions and answers | {{retention.ai_queries}} days by default; the company may set a different period within the limits of its plan |
| Questions in the managers' queue | {{retention.unanswered_questions}} days after they are answered or dismissed |
| Incidents and tasks | while the company workspace exists, unless the company erases your data earlier |
| Record of opened documents and other audit entries | {{retention.audit_logs}} days by default |
| Telegram identity | until you unlink the account or the company removes you |

When the company deletes its workspace, all workspace data is deleted after a grace period of {{retention.workspace_deletion_grace}} days.

## 5. Your rights

You can ask for a copy of your data, for its correction or erasure, and object to processing. Because the company is the controller, send your request to your manager or the company administrator: they can export your data or erase it in {{product.name}}. You can also write to {{company.privacy_email}}; we will forward your request to the company and help it to respond.

## 6. Commands

- /privacy shows a short summary of this notice.
- /history shows your last questions.
- /unlink disconnects your Telegram account from the company bot. After that the bot no longer answers you and records nothing new about you.

## 7. Changes

If this notice changes in a way that matters to you, the bot shows the new version and asks you to confirm that you have read it before you continue.
