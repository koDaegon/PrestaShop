require('module-alias/register');

// Using chai
const {expect} = require('chai');

const helper = require('@utils/helpers');
const loginCommon = require('@commonTests/loginBO');
const {moduleCategories} = require('@data/demo/moduleCategories');

// Import pages
const dashboardPage = require('@pages/BO/dashboard');
const moduleManagerPage = require('@pages/BO/modules/moduleManager');

// Import test context
const testContext = require('@utils/testContext');

const baseContext = 'functional_BO_modules_moduleManager_filterModulesByCategory';


let browserContext;
let page;

describe('Filter modules by Categories', async () => {
  // before and after functions
  before(async function () {
    browserContext = await helper.createBrowserContext(this.browser);
    page = await helper.newTab(browserContext);
  });

  after(async () => {
    await helper.closeBrowserContext(browserContext);
  });

  it('should login in BO', async function () {
    await loginCommon.loginBO(this, page);
  });

  it('should go to module manager page', async function () {
    await testContext.addContextItem(this, 'testIdentifier', 'goToModuleManagerPage', baseContext);

    await dashboardPage.goToSubMenu(
      page,
      dashboardPage.modulesParentLink,
      dashboardPage.moduleManagerLink,
    );

    await moduleManagerPage.closeSfToolBar(page);

    const pageTitle = await moduleManagerPage.getPageTitle(page);
    await expect(pageTitle).to.contains(moduleManagerPage.pageTitle);
  });

  describe('Filter modules by categories', async () => {
    moduleCategories.forEach((category) => {
      it(`should filter by category : '${category}'`, async function () {
        await testContext.addContextItem(this, 'testIdentifier', `filterByCategory${category}`, baseContext);

        // Filter modules by categories
        await moduleManagerPage.filterByCategory(page, category);

        // Check first category displayed
        const firstBlockTitle = await moduleManagerPage.getBlockModuleTitle(page, 1);
        await expect(firstBlockTitle).to.equal(category);
      });
    });
  });
});