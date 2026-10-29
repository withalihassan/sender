
import getpass
import shutil
import tempfile
import time

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import (
    TimeoutException,
    WebDriverException,
    ElementClickInterceptedException,
    ElementNotInteractableException,
)

AWS_URL = "https://aws.amazon.com/"

NAV_SELECTOR = (
    "#global-nav-desktop > div.rggn_24d84766 > div > "
    "ul.rggn_be2a22f9.rggn_0ec3f087 > li:nth-child(2) > a > div"
)

ROOT_SIGNIN_SELECTOR = "button#root_account_signin"
EMAIL_SELECTOR = "#resolving_input"
EMAIL_NEXT_SELECTOR = "#next_button"
PASSWORD_SELECTOR = "#password"
SIGNIN_BUTTON_SELECTOR = "#signin_button"

# New steps
TROUBLE_SIGNIN_SELECTOR = '[data-testid="troubleshoot-link"]'
ALTERNATIVE_FACTORS_SELECTOR = 'input[type="radio"][value="afa"]'
ALTERNATIVE_FACTORS_NEXT_SELECTOR = (
    'button[data-testid="afa-submit-button"]'
)


def wait_and_click(driver, selector, timeout=45):
    """Wait for an element and click it."""
    element = WebDriverWait(driver, timeout).until(
        EC.element_to_be_clickable((By.CSS_SELECTOR, selector))
    )

    driver.execute_script(
        "arguments[0].scrollIntoView({block:'center'});",
        element
    )

    try:
        element.click()
    except (
        ElementClickInterceptedException,
        ElementNotInteractableException,
    ):
        driver.execute_script("arguments[0].click();", element)

    return element


def switch_to_new_tab(driver, old_handles, timeout=30):
    """Wait for a new tab and switch to it once."""
    WebDriverWait(driver, timeout, poll_frequency=0.5).until(
        lambda d: any(
            handle not in old_handles for handle in d.window_handles
        )
    )

    new_handles = [
        handle for handle in driver.window_handles
        if handle not in old_handles
    ]

    if not new_handles:
        raise TimeoutException("The new AWS tab was not found.")

    driver.switch_to.window(new_handles[0])
    print("Switched to AWS sign-in tab:", driver.current_url)


def select_alternative_factors(driver):
    """Open the recovery option, select the radio tile, and click Next."""

    # STEP 1: Click Trouble signing in?
    print("Waiting for Trouble signing in link...")

    wait_and_click(
        driver,
        TROUBLE_SIGNIN_SELECTOR,
        timeout=60
    )

    print("Trouble signing in link clicked.")

    # STEP 2: Select the alternative factors radio option.
    print("Waiting for the alternative factors option...")

    radio = WebDriverWait(driver, 60).until(
        EC.presence_of_element_located(
            (By.CSS_SELECTOR, ALTERNATIVE_FACTORS_SELECTOR)
        )
    )

    driver.execute_script(
        "arguments[0].scrollIntoView({block:'center'});",
        radio
    )

    try:
        radio.click()
    except WebDriverException:
        driver.execute_script("arguments[0].click();", radio)

    # Verify that the option is selected.
    WebDriverWait(driver, 10).until(
        lambda d: d.execute_script(
            "return arguments[0].checked;",
            radio
        )
    )

    print("Alternative factors option selected.")

    # STEP 3: Click its Next button.
    print("Waiting for the alternative factors Next button...")

    wait_and_click(
        driver,
        ALTERNATIVE_FACTORS_NEXT_SELECTOR,
        timeout=45
    )

    print("Alternative factors Next button clicked.")


def keep_browser_open(driver):
    print("\nAutomation reached the requested stopping point.")
    print("Chrome will remain open. Close it manually when finished.")

    while True:
        try:
            if not driver.window_handles:
                break
        except WebDriverException:
            break

        time.sleep(1)


def main():
    email = input("Enter AWS email: ").strip()
    password = getpass.getpass("Enter AWS password: ")

    if not email or not password:
        print("Email and password are required.")
        return

    driver = None
    profile_dir = tempfile.mkdtemp(
        prefix="aws_chrome_profile_"
    )

    try:
        # Create a fresh Chrome profile.
        options = webdriver.ChromeOptions()
        options.add_argument(f"--user-data-dir={profile_dir}")
        options.add_argument("--no-first-run")
        options.add_argument("--no-default-browser-check")

        driver = webdriver.Chrome(options=options)
        driver.maximize_window()
        driver.set_page_load_timeout(60)

        # STEP 1: Open AWS.
        print("Opening AWS...")
        driver.get(AWS_URL)

        # STEP 2: Click the navigation link, which opens a new tab.
        old_handles = set(driver.window_handles)

        print("Clicking AWS navigation sign-in link...")
        wait_and_click(driver, NAV_SELECTOR)

        # STEP 3: Switch to the newly opened tab.
        switch_to_new_tab(driver, old_handles)

        # STEP 4: Click root-user sign-in.
        print("Waiting for root-user sign-in button...")

        wait_and_click(
            driver,
            ROOT_SIGNIN_SELECTOR,
            timeout=60
        )

        # STEP 5: Enter email.
        print("Waiting for email field...")

        email_field = WebDriverWait(driver, 60).until(
            EC.visibility_of_element_located(
                (By.CSS_SELECTOR, EMAIL_SELECTOR)
            )
        )

        email_field.clear()
        email_field.send_keys(email)

        # STEP 6: Submit email.
        print("Submitting email...")
        wait_and_click(driver, EMAIL_NEXT_SELECTOR)

        # STEP 7: Allow manual CAPTCHA solving.
        print("\nSolve any CAPTCHA manually in Chrome.")
        print("Waiting for the password field for up to 10 minutes...")

        password_field = WebDriverWait(
            driver,
            600,
            poll_frequency=1
        ).until(
            EC.visibility_of_element_located(
                (By.CSS_SELECTOR, PASSWORD_SELECTOR)
            )
        )

        # STEP 8: Enter password.
        print("Entering password...")
        password_field.clear()
        password_field.send_keys(password)

        # STEP 9: Submit the password form.
        print("Clicking Sign in...")
        wait_and_click(driver, SIGNIN_BUTTON_SELECTOR)

        # STEP 10: Open the account recovery option and continue.
        select_alternative_factors(driver)

        # STOP HERE for your next additions.
        keep_browser_open(driver)

    except TimeoutException as error:
        print("\nTIMEOUT:", error)

        if driver is not None:
            try:
                print("Current URL:", driver.current_url)
                print("Page title:", driver.title)
                driver.save_screenshot("aws_error.png")
                print("Screenshot saved as aws_error.png")
            except WebDriverException:
                pass

            input("Press Enter to close the automation...")

    except WebDriverException as error:
        print("\nBrowser error:", error)
        input("Press Enter to exit...")

    finally:
        if driver is not None:
            try:
                driver.quit()
            except WebDriverException:
                pass

        shutil.rmtree(profile_dir, ignore_errors=True)
        print("Temporary Chrome profile cleaned up.")


if __name__ == "__main__":
    main()
